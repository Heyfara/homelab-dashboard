<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\HomeAssistant\HomeAssistantClient;
use App\Source\SourceUnavailableException;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Énergie de la maison, lue dans Home Assistant : puissance du moment
 * (solaire, réseau), énergie et coût du jour, courbe heure par heure des dernières 24 h et
 * barres des 30 derniers jours. Compteurs et prix viennent du tableau Énergie
 * de HA ; seuls les capteurs de puissance instantanée sont à donner.
 *
 *     - type: energy
 *       service: homeassistant
 *       solar_power: sensor.prise_panneaux_solaires_power   # W
 *       grid_power: sensor.linky_papp                       # VA (≈ W)
 *       span: 2
 *       rows: 2
 *       refresh: 60
 */
#[AsCard('energy')]
final readonly class EnergyCard implements CardTypeInterface
{
    private const DAYS = 30;

    public function __construct(
        private HomeAssistantClient $homeAssistant,
        /** Maintenant (heure de Paris), pour les tests */
        private ?string $now = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));
        $sensor = static fn (?string $id): bool => null === $id || 1 === preg_match('/^sensor\.[a-z0-9_]+$/', $id);

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setInfo('service', 'le service Home Assistant du catalogue')
            ->setDefault('solar_power', null)
            ->setAllowedTypes('solar_power', ['null', 'string'])
            ->setAllowedValues('solar_power', $sensor)
            ->setInfo('solar_power', 'capteur de puissance solaire instantanée (W) : sensor.…')
            ->setDefault('grid_power', null)
            ->setAllowedTypes('grid_power', ['null', 'string'])
            ->setAllowedValues('grid_power', $sensor)
            ->setInfo('grid_power', 'capteur de puissance tirée du réseau (W ou VA) : sensor.…');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return ['title' => $card->title ?? 'Énergie', 'url' => rtrim((string) $service->url, '/').'/energy'];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];
        $api = $service->check ?? (string) $service->url;
        $paris = new \DateTimeZone('Europe/Paris');
        $now = new \DateTimeImmutable($this->now ?? 'now', $paris);
        $hour = $now->setTime((int) $now->format('G'), 0);
        $firstDay = $now->setTime(0, 0)->modify(\sprintf('-%d days', self::DAYS - 1));

        $first = $this->homeAssistant->query($api, ['prefs' => ['type' => 'energy/get_prefs'], 'states' => ['type' => 'get_states']]);
        $states = array_column($first['states'] ?? [], 'state', 'entity_id');

        $grid = $solar = [];
        $price = null;
        foreach ($first['prefs']['energy_sources'] ?? [] as $source) {
            $from = $source['stat_energy_from'] ?? null;
            if (!\is_string($from)) {
                continue;
            }
            if ('grid' === ($source['type'] ?? null)) {
                $grid[] = $from;
                $price ??= isset($source['number_energy_price']) ? (float) $source['number_energy_price'] : self::number($states, $source['entity_energy_price'] ?? null);
            } elseif ('solar' === ($source['type'] ?? null)) {
                $solar[] = $from;
            }
        }
        if ([] === $grid && [] === $solar) {
            throw new SourceUnavailableException('Tableau Énergie de Home Assistant non configuré (Paramètres → Tableaux de bord → Énergie).');
        }

        $statistics = static fn (string $period, \DateTimeImmutable $start, \DateTimeImmutable $end): array => [
            'type' => 'recorder/statistics_during_period',
            'start_time' => $start->format(\DATE_ATOM),
            'end_time' => $end->format(\DATE_ATOM),
            'statistic_ids' => [...$grid, ...$solar],
            'period' => $period,
            'types' => ['change'],
        ];
        $stats = $this->homeAssistant->query($api, [
            // Heures pleines seulement : le compteur Linky avance par 0,1 kWh, trop
            // grossier pour des tranches de 5 min ; l'heure en cours est dans « now ».
            'day' => $statistics('hour', $hour->modify('-1 day'), $hour),
            'month' => $statistics('day', $firstDay, $now),
        ]);

        // kWh par heure → puissance moyenne en W.
        $day = self::series($stats['day'] ?? [], $grid, $solar, static fn (int $ms): string => self::at($ms, $paris)->format(\DATE_ATOM), static fn (float $kwh): float => round($kwh * 1000));
        $month = self::series($stats['month'] ?? [], $grid, $solar, static fn (int $ms): string => self::at($ms, $paris)->format('Y-m-d'), static fn (float $kwh): float => round($kwh, 3));

        $todayIndex = array_search($now->format('Y-m-d'), $month['at'], true);
        $todayGrid = [] === $grid ? null : (false === $todayIndex ? 0.0 : ($month['grid'][$todayIndex] ?? 0.0));
        $todaySolar = [] === $solar ? null : (false === $todayIndex ? 0.0 : ($month['solar'][$todayIndex] ?? 0.0));

        return [
            'now' => ['solar' => self::number($states, $card->options['solar_power']), 'grid' => self::number($states, $card->options['grid_power'])],
            'price' => $price,
            'today' => [
                'solar' => $todaySolar,
                'grid' => $todayGrid,
                'cost' => null !== $price && null !== $todayGrid ? round($todayGrid * $price, 2) : null,
                // Plafond : la part du solaire renvoyée au réseau n'est pas mesurée.
                'saved' => null !== $price && null !== $todaySolar ? round($todaySolar * $price, 2) : null,
            ],
            'day' => $day,
            'month' => $month,
        ];
    }

    /**
     * Statistiques HA (une liste par compteur) → une seule série par sorte
     * (réseau, solaire), alignée sur les débuts de tranche ; null si aucune
     * mesure de cette sorte pour la tranche.
     *
     * @param array<string, list<array{start: int, change?: float|null}>> $byId
     * @param list<string>                                               $grid
     * @param list<string>                                               $solar
     *
     * @return array{at: list<string>, solar: list<float|null>, grid: list<float|null>}
     */
    private static function series(array $byId, array $grid, array $solar, \Closure $label, \Closure $convert): array
    {
        $sums = [];
        foreach (['grid' => $grid, 'solar' => $solar] as $kind => $ids) {
            foreach ($ids as $id) {
                foreach ($byId[$id] ?? [] as $row) {
                    if (null !== ($row['change'] ?? null)) {
                        $sums[(int) $row['start']][$kind] = ($sums[(int) $row['start']][$kind] ?? 0.0) + (float) $row['change'];
                    }
                }
            }
        }
        ksort($sums);

        $pick = static fn (string $kind): array => array_values(array_map(static fn (array $s): ?float => isset($s[$kind]) ? $convert($s[$kind]) : null, $sums));

        return ['at' => array_values(array_map($label, array_keys($sums))), 'solar' => $pick('solar'), 'grid' => $pick('grid')];
    }

    private static function at(int $ms, \DateTimeZone $zone): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.intdiv($ms, 1000)))->setTimezone($zone);
    }

    /**
     * @param array<string, string> $states
     */
    private static function number(array $states, ?string $entity): ?float
    {
        $state = null !== $entity ? ($states[$entity] ?? null) : null;

        return is_numeric($state) ? (float) $state : null;
    }
}
