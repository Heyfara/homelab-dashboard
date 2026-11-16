<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Profile\CardCredentials;
use App\Source\Credentials;
use App\Source\Gw2\Gw2Client;
use App\Source\Gw2\WorldBossSchedule;
use App\Source\SourceUnavailableException;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Boss du monde de Guild Wars 2 : le prochain pas encore fait aujourd'hui, et
 * les passages suivants. L'horaire est fixe (WorldBossSchedule) ; seuls les
 * boss déjà faits viennent de l'API, avec la clé du compte. Sans clé ou si
 * l'API ne répond pas, l'horaire reste affiché.
 *
 *     - type: gw2-bosses
 *       credentials: profile   # même clé que la carte gw2
 *       count: 4               # passages listés sous le prochain boss
 *       span: 2
 */
#[AsCard('gw2-bosses')]
final readonly class Gw2BossesCard implements CardTypeInterface
{
    public const TIMERS_URL = 'https://wiki.guildwars2.com/wiki/Event_timers';

    /** Passages renvoyés : 24 h, pour que le navigateur décompte sans redemander */
    private const SLOTS = 96;

    public function __construct(
        private Gw2Client $gw2,
        private CardCredentials $credentials,
        /** Instant présent (ISO 8601), pour les tests ; sinon maintenant */
        private ?string $now = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $resolver
            ->setDefault('count', 4)
            ->setAllowedTypes('count', 'int')
            ->setAllowedValues('count', static fn (int $n): bool => $n >= 1 && $n <= 20)
            ->setInfo('count', 'passages listés sous le prochain boss (1 à 20)')
            ->setDefault('credentials', null)
            ->setAllowedTypes('credentials', ['null', 'string'])
            ->setAllowedValues('credentials', static fn (?string $c): bool => null === $c || Credentials::isValidVariant($c))
            ->setInfo('credentials', 'profile = clé saisie dans « Ma config » ; un nom = variable GW2_API_KEY_<NOM> ; absent = GW2_API_KEY')
            ->setDefault('api', 'https://api.guildwars2.com')
            ->setAllowedTypes('api', 'string');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return ['title' => $card->title ?? 'Boss du monde', 'link' => self::TIMERS_URL, 'count' => $card->options['count']];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $now = new \DateTimeImmutable($this->now ?? 'now', new \DateTimeZone('UTC'));
        $resetAt = $now->setTime(0, 0)->modify('+1 day');

        $killed = null;
        $error = null;
        try {
            $key = $this->credentials->resolve($card, 'GW2_API_KEY', 'gw2');
            $killed = array_values(array_intersect(WorldBossSchedule::ids(), $this->gw2->accountWorldBosses($card->options['api'], $key)));
        } catch (SourceUnavailableException $e) {
            $error = $e->getMessage();
        }

        $slots = [];
        foreach (WorldBossSchedule::upcoming($now, self::SLOTS) as $slot) {
            $slots[] = [
                'id' => $slot['id'],
                'name' => $slot['name'],
                'startsAt' => $slot['startsAt']->format(\DATE_ATOM),
                // fait aujourd'hui : le passage d'après la réinitialisation est à refaire
                'done' => null !== $killed && $slot['startsAt'] < $resetAt && \in_array($slot['id'], $killed, true),
            ];
        }

        return [
            'slots' => $slots,
            'done' => null === $killed ? null : \count($killed),
            'total' => \count(WorldBossSchedule::ids()),
            'resetAt' => $resetAt->format(\DATE_ATOM),
            'doneError' => $error,
        ];
    }
}
