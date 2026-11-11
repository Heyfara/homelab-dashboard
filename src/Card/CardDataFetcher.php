<?php

namespace App\Card;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Profile\ProfileCredentials;
use App\Profile\ProfileSettings;
use App\Source\SourceUnavailableException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Données vivantes d'une carte, avec cache et capture des erreurs : chaque type
 * de carte n'a qu'à lire sa source.
 *
 * Cache « périmé puis rafraîchi » : passé `refresh` secondes, la dernière valeur
 * est encore servie tout de suite (stale: true) et la source est relue une fois
 * la réponse partie. Seule une carte jamais lue (ou pas lue depuis KEEP) attend
 * sa source. Un échec de cette relecture est servi comme erreur aux lectures
 * suivantes, jusqu'à ce qu'une relecture réussisse.
 */
final readonly class CardDataFetcher
{
    /** Durée de vie d'une valeur, périmée ou non : au-delà, la carte attend de nouveau sa source. */
    public const KEEP = 2 * 86400;

    /** Une relecture en arrière-plan à la fois par carte (verrou posé dans le cache, sans garantie d'atomicité). */
    private const LOCK_SECONDS = 30;

    public function __construct(
        private CardTypeRegistry $registry,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private DeferredRefresh $deferred,
        private ?ProfileCredentials $profileCredentials = null,
        private ?ProfileSettings $profileSettings = null,
    ) {
    }

    /**
     * @param ?string $feed un flux de la carte (CardFeedsInterface), sinon ses données principales
     */
    public function fetch(CardInstance $card, Dashboard $dashboard, ?string $feed = null): CardResult
    {
        // Les options font partie de la clé : une carte modifiée dans le YAML
        // n'attend pas l'expiration de l'ancienne version.
        // Les clés et réglages du profil aussi : ce qui est saisi dans « Ma config » sert tout de suite.
        $revision = null === $card->profile ? '' : $this->profileCredentials?->revision($card->profile).'|'.$this->profileSettings?->revision($card->profile);
        $key = 'card.'.hash('xxh128', $card->id.'|'.$feed.'|'.serialize($card->options).'|'.$revision);

        try {
            $refresh = $card->refresh;
            if (null !== $feed) {
                $refresh = $this->registry->feeds($card)[$feed] ?? throw new \InvalidArgumentException(\sprintf('Flux inconnu pour la carte %s : %s', $card->id, $feed));
            }

            $item = $this->cache->getItem($key);
            /** @var array{data: array<string, mixed>, fetchedAt: \DateTimeImmutable, error?: string}|null $entry */
            $entry = $item->isHit() ? $item->get() : null;

            if (null === $entry) {
                $entry = $this->read($card, $dashboard, $feed);
                $this->cache->save($item->set($entry)->expiresAfter(self::KEEP));

                return CardResult::ok($entry['data'], $entry['fetchedAt']);
            }

            $stale = time() - $entry['fetchedAt']->getTimestamp() >= $refresh;
            if ($stale) {
                $this->deferred->defer($key, fn () => $this->refreshInBackground($key, $card, $dashboard, $feed));
            }

            if (isset($entry['error'])) {
                return CardResult::error($entry['error']);
            }

            return CardResult::ok($entry['data'], $entry['fetchedAt'], $stale);
        } catch (SourceUnavailableException $e) {
            $this->logger->warning('Carte {card} : {message}', ['card' => $card->id, 'message' => $e->getMessage()]);

            return CardResult::error($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Carte {card} : erreur inattendue', ['card' => $card->id, 'exception' => $e]);

            return CardResult::error(\sprintf('Erreur interne : %s', $e->getMessage()));
        }
    }

    /**
     * @return array{data: array<string, mixed>, fetchedAt: \DateTimeImmutable}
     */
    private function read(CardInstance $card, Dashboard $dashboard, ?string $feed): array
    {
        $type = $this->registry->get($card->type);

        return [
            'data' => null !== $feed && $type instanceof CardFeedsInterface ? $type->fetchFeed($feed, $card, $dashboard) : $type->fetch($card, $dashboard),
            'fetchedAt' => new \DateTimeImmutable(),
        ];
    }

    private function refreshInBackground(string $key, CardInstance $card, Dashboard $dashboard, ?string $feed): void
    {
        $lock = $this->cache->getItem($key.'.lock');
        if ($lock->isHit()) {
            return;
        }
        $this->cache->save($lock->set(true)->expiresAfter(self::LOCK_SECONDS));

        try {
            $entry = $this->read($card, $dashboard, $feed);
        } catch (\Throwable $e) {
            $message = $e instanceof SourceUnavailableException ? $e->getMessage() : \sprintf('Erreur interne : %s', $e->getMessage());
            $this->logger->warning('Carte {card} : {message}', ['card' => $card->id, 'message' => $message, 'exception' => $e]);
            $item = $this->cache->getItem($key);
            // L'ancienne date reste : la relecture suivante sera tentée tout de suite.
            $entry = $item->isHit() ? ['error' => $message] + $item->get() : null;
        }

        if (null !== $entry) {
            $this->cache->save($this->cache->getItem($key)->set($entry)->expiresAfter(self::KEEP));
        }
        $this->cache->deleteItem($key.'.lock');
    }
}
