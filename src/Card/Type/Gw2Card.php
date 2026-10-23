<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Profile\CardCredentials;
use App\Source\Credentials;
use App\Source\Gw2\Gw2Client;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Compte Guild Wars 2 : portefeuille, or à récupérer au comptoir, Chambre forte
 * du Sorcier (objectifs restants du jour et de la semaine), artisanat quotidien.
 *
 *     - type: gw2
 *       currencies: [1, 63, 2, 4]   # identifiants /v2/currencies : or, acclamations astrales, karma, gemmes
 *       credentials: profile       # clé saisie dans « Ma config » du profil ; ou un nom : GW2_API_KEY_<NOM> ; sinon GW2_API_KEY
 *       span: 2
 *       rows: 2
 */
#[AsCard('gw2')]
final readonly class Gw2Card implements CardTypeInterface
{
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
            ->setDefault('currencies', [1, 63, 2, 4])
            ->setAllowedTypes('currencies', 'int[]')
            ->setInfo('currencies', 'monnaies affichées, identifiants de /v2/currencies (1 = or)')
            ->setDefault('credentials', null)
            ->setAllowedTypes('credentials', ['null', 'string'])
            ->setAllowedValues('credentials', static fn (?string $c): bool => null === $c || Credentials::isValidVariant($c))
            ->setInfo('credentials', 'profile = clé saisie dans « Ma config » ; un nom = variable GW2_API_KEY_<NOM> ; absent = GW2_API_KEY')
            ->setDefault('api', 'https://api.guildwars2.com')
            ->setAllowedTypes('api', 'string');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return ['title' => $card->title ?? 'Guild Wars 2'];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $api = $card->options['api'];
        $key = $this->credentials->resolve($card, 'GW2_API_KEY', 'gw2');
        $now = new \DateTimeImmutable($this->now ?? 'now', new \DateTimeZone('UTC'));
        $account = $this->gw2->account($api, $key);
        $icons = $this->gw2->fileIcons($api, ['map_waypoint', 'ui_coin_gold', 'ui_coin_silver', 'ui_coin_copper']);
        $delivery = $this->gw2->delivery($api, $key);
        [$crafted, $craftable] = $this->gw2->dailyCrafting($api, $key);

        $periods = ['daily' => self::dailyReset($now), 'weekly' => self::weeklyReset($now)];
        $vault = ['objectives' => []];
        foreach ($periods as $period => $resetAt) {
            $v = $this->gw2->wizardsVault($api, $period, $key);
            $vault[$period] = [
                'done' => (int) $v['meta_progress_current'],
                'total' => (int) $v['meta_progress_complete'],
                'claimed' => (bool) $v['meta_reward_claimed'],
                'acclaim' => (int) $v['meta_reward_astral'],
                'resetAt' => $resetAt->format(\DATE_ATOM),
            ];
            foreach ($v['objectives'] ?? [] as $o) {
                if ((int) $o['progress_current'] < (int) $o['progress_complete']) {
                    $vault['objectives'][] = ['period' => $period, 'title' => (string) $o['title'], 'current' => (int) $o['progress_current'], 'complete' => (int) $o['progress_complete'], 'acclaim' => (int) $o['acclaim']];
                }
            }
        }

        return [
            'account' => ['name' => (string) $account['name'], 'fractalLevel' => (int) ($account['fractal_level'] ?? 0), 'wvwRank' => (int) ($account['wvw']['rank'] ?? $account['wvw_rank'] ?? 0)],
            'icon' => $icons['map_waypoint'] ?? null,
            'coinIcons' => ['gold' => $icons['ui_coin_gold'] ?? null, 'silver' => $icons['ui_coin_silver'] ?? null, 'copper' => $icons['ui_coin_copper'] ?? null],
            'wallet' => $this->gw2->wallet($api, $card->options['currencies'], $key),
            'delivery' => ['coins' => $delivery['coins'], 'items' => \count($delivery['items'])],
            'vault' => $vault,
            'crafting' => ['done' => $crafted, 'total' => $craftable],
        ];
    }

    /** Réinitialisation quotidienne : chaque jour à 0 h UTC. */
    private static function dailyReset(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTime(0, 0)->modify('+1 day');
    }

    /** Réinitialisation hebdomadaire : le lundi à 7 h 30 UTC. */
    private static function weeklyReset(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $monday = $now->modify('monday this week')->setTime(7, 30);

        return $monday > $now ? $monday : $monday->modify('+1 week');
    }
}
