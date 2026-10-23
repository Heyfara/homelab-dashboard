<?php

namespace App\Card;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Profile\ProfileCredentials;
use App\Profile\ProfileSettings;
use App\Source\SourceUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Données vivantes d'une carte, avec cache et capture des erreurs : chaque type
 * de carte n'a qu'à lire sa source.
 */
final readonly class CardDataFetcher
{
    public function __construct(
        private CardTypeRegistry $registry,
        private CacheInterface $cache,
        private LoggerInterface $logger,
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

            $entry = $this->cache->get($key, function (ItemInterface $item) use ($card, $dashboard, $feed, $refresh): array {
                $item->expiresAfter($refresh);
                $type = $this->registry->get($card->type);

                return [
                    'data' => null !== $feed && $type instanceof CardFeedsInterface ? $type->fetchFeed($feed, $card, $dashboard) : $type->fetch($card, $dashboard),
                    'fetchedAt' => new \DateTimeImmutable(),
                ];
            });
        } catch (SourceUnavailableException $e) {
            $this->logger->warning('Carte {card} : {message}', ['card' => $card->id, 'message' => $e->getMessage()]);

            return CardResult::error($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Carte {card} : erreur inattendue', ['card' => $card->id, 'exception' => $e]);

            return CardResult::error(\sprintf('Erreur interne : %s', $e->getMessage()));
        }

        return CardResult::ok($entry['data'], $entry['fetchedAt']);
    }
}
