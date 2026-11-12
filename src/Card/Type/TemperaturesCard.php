<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\HomeAssistant\HomeAssistantClient;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Température (et humidité) de chaque pièce, lue dans Home Assistant, avec
 * l'évolution sur 24 h et la consigne du chauffage s'il y a un thermostat
 * dans la pièce. Les capteurs sont trouvés par pièce (registres de HA) :
 * rien à lister ici.
 *
 *     - type: temperatures
 *       service: homeassistant
 *       outdoor: jardin                  # pièce HA montrée à part, « Dehors »
 *       areas: [salon, chambre_parents]  # ordre et choix ; vide = toutes
 *       span: 2
 *       rows: 2
 *       refresh: 300
 */
#[AsCard('temperatures')]
final readonly class TemperaturesCard implements CardTypeInterface
{
    public function __construct(
        private HomeAssistantClient $homeAssistant,
        /** Maintenant (heure de Paris), pour les tests */
        private ?string $now = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));
        $areaId = static fn (?string $id): bool => null === $id || 1 === preg_match('/^[a-z0-9_]+$/', $id);

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setInfo('service', 'le service Home Assistant du catalogue')
            ->setDefault('areas', [])
            ->setAllowedTypes('areas', 'string[]')
            ->setAllowedValues('areas', static fn (array $ids): bool => [] === array_filter($ids, static fn (string $id): bool => !$areaId($id)))
            ->setInfo('areas', 'identifiants des pièces HA à montrer, dans l\'ordre ; vide = toutes celles qui ont une sonde')
            ->setDefault('outdoor', null)
            ->setAllowedTypes('outdoor', ['null', 'string'])
            ->setAllowedValues('outdoor', $areaId)
            ->setInfo('outdoor', 'pièce HA de la sonde extérieure, montrée à part');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return ['title' => $card->title ?? 'Températures', 'url' => rtrim((string) $service->url, '/')];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];
        $api = $service->check ?? (string) $service->url;
        $paris = new \DateTimeZone('Europe/Paris');
        $now = new \DateTimeImmutable($this->now ?? 'now', $paris);
        $hour = $now->setTime((int) $now->format('G'), 0);

        $ha = $this->homeAssistant->query($api, [
            'areas' => ['type' => 'config/area_registry/list'],
            'devices' => ['type' => 'config/device_registry/list'],
            'entities' => ['type' => 'config/entity_registry/list_for_display'],
            'states' => ['type' => 'get_states'],
        ]);

        $areaNames = array_column($ha['areas'] ?? [], 'name', 'area_id');
        $deviceAreas = array_column($ha['devices'] ?? [], 'area_id', 'id');
        $states = array_column($ha['states'] ?? [], null, 'entity_id');

        // Par pièce : sondes de température et d'humidité, thermostats ; les
        // appareils qui ont un thermostat sont notés pour passer après le thermomètre.
        $byArea = [];
        $heaters = [];
        foreach ($ha['entities']['entities'] ?? [] as $entity) {
            $id = $entity['ei'] ?? '';
            $area = $entity['ai'] ?? ($deviceAreas[$entity['di'] ?? ''] ?? null);
            if (null === $area || isset($entity['ec']) || !isset($states[$id])) {
                continue;
            }
            if (str_starts_with($id, 'climate.')) {
                $byArea[$area]['climate'][] = $id;
                $heaters[$entity['di'] ?? ''] = true;
            } elseif (str_starts_with($id, 'sensor.')) {
                $class = $states[$id]['attributes']['device_class'] ?? null;
                if (\in_array($class, ['temperature', 'humidity'], true)) {
                    $byArea[$area][$class][] = ['id' => $id, 'heater' => $entity['di'] ?? null];
                }
            }
        }
        $pick = static function (array $sensors) use ($heaters): ?string {
            usort($sensors, static fn (array $a, array $b): int => isset($heaters[$a['heater']]) <=> isset($heaters[$b['heater']]));

            return $sensors[0]['id'] ?? null;
        };
        $value = static function (?string $id) use ($states): ?float {
            $state = null !== $id ? ($states[$id]['state'] ?? null) : null;

            return is_numeric($state) ? round((float) $state, 1) : null;
        };

        $outdoorArea = $card->options['outdoor'];
        $wanted = [] !== $card->options['areas'] ? $card->options['areas'] : array_keys($areaNames);
        $rooms = [];
        foreach ($wanted as $area) {
            $temperature = $pick($byArea[$area]['temperature'] ?? []);
            if ($area === $outdoorArea || null === $temperature) {
                continue;
            }
            $climateId = $byArea[$area]['climate'][0] ?? null;
            $climate = null !== $climateId ? $states[$climateId] : null;
            $rooms[] = [
                'area' => $area,
                'name' => $areaNames[$area] ?? $area,
                'sensor' => $temperature,
                'temperature' => $value($temperature),
                'humidity' => $value($pick($byArea[$area]['humidity'] ?? [])),
                'climate' => null === $climate ? null : [
                    'target' => isset($climate['attributes']['temperature']) ? (float) $climate['attributes']['temperature'] : null,
                    'action' => $climate['attributes']['hvac_action'] ?? null,
                    'mode' => $climate['state'],
                ],
            ];
        }

        $outdoor = null;
        if (null !== $outdoorArea && null !== ($sensor = $pick($byArea[$outdoorArea]['temperature'] ?? []))) {
            $outdoor = [
                'name' => $areaNames[$outdoorArea] ?? $outdoorArea,
                'temperature' => $value($sensor),
                'humidity' => $value($pick($byArea[$outdoorArea]['humidity'] ?? [])),
            ];
        }

        // Moyenne de chaque heure pleine, 24 dernières heures.
        $history = [];
        if ([] !== $rooms) {
            $history = $this->homeAssistant->query($api, ['stats' => [
                'type' => 'recorder/statistics_during_period',
                'start_time' => $hour->modify('-1 day')->format(\DATE_ATOM),
                'end_time' => $hour->format(\DATE_ATOM),
                'statistic_ids' => array_column($rooms, 'sensor'),
                'period' => 'hour',
                'types' => ['mean'],
            ]])['stats'] ?? [];
        }
        $starts = [];
        foreach ($history as $rows) {
            foreach ($rows as $row) {
                $starts[(int) $row['start']] = true;
            }
        }
        ksort($starts);
        $starts = array_keys($starts);

        foreach ($rooms as &$room) {
            $means = array_column($history[$room['sensor']] ?? [], 'mean', 'start');
            $room['history'] = array_map(static fn (int $start): ?float => isset($means[$start]) ? round((float) $means[$start], 1) : null, $starts);
            $climate = $room['climate'];
            unset($room['sensor'], $room['climate']);
            $room['climate'] = $climate;
        }
        unset($room);

        return [
            'outdoor' => $outdoor,
            'at' => array_map(static fn (int $ms): string => (new \DateTimeImmutable('@'.intdiv($ms, 1000)))->setTimezone($paris)->format(\DATE_ATOM), $starts),
            'rooms' => $rooms,
        ];
    }
}
