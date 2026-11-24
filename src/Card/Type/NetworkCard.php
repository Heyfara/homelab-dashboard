<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardFeedsInterface;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Source\Freebox\FreeboxClient;
use App\Source\Freebox\FreeboxHost;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Onglet Réseau : appareils vus par la Freebox (récemment ou en ce moment) et
 * débit Internet (flux `rates`, toutes les 5 s). Prévue pour occuper seule son
 * onglet : sa hauteur suit son contenu.
 *
 *     - type: network
 *       devices:                       # facultatif : type ou nom par adresse MAC
 *         "AA:BB:CC:DD:EE:FF": { type: server, name: alpha }
 */
#[AsCard('network')]
final readonly class NetworkCard implements CardTypeInterface, CardFeedsInterface
{
    /** Types d'appareil connus du front (icônes) */
    public const TYPES = ['box', 'network', 'server', 'computer', 'laptop', 'phone', 'tablet', 'tv', 'printer', 'speaker', 'camera', 'iot', 'console', 'other'];

    /**
     * host_type de la Freebox → type du dashboard. `workstation`, type par défaut
     * de la Freebox, n'y est pas : il ne dit rien, le fabricant décide.
     */
    private const FREEBOX_TYPES = [
        'laptop' => 'laptop',
        'smartphone' => 'phone',
        'ip_phone' => 'phone',
        'tablet' => 'tablet',
        'television' => 'tv',
        'multimedia_device' => 'tv',
        'printer' => 'printer',
        'ip_camera' => 'camera',
        'nas' => 'server',
        'networking_device' => 'network',
        'vg_console' => 'console',
        'freebox_player' => 'tv',
        'freebox_pop' => 'tv', // le Player Pop ; le Server, routeur, n'est pas dans la liste
        'freebox_server' => 'box',
        'freebox_delta' => 'box',
        'freebox_mini' => 'box',
        'freebox_one' => 'box',
        'freebox_hd' => 'tv',
        'freebox_wifi' => 'network',
        'freebox_repeater' => 'network',
    ];

    /** Début du nom de fabricant → type, quand la Freebox n'en sait rien */
    private const VENDOR_TYPES = [
        'Espressif' => 'iot',
        'Netatmo' => 'iot',
        'Withings' => 'iot',
        'Nest Labs' => 'iot',
        'Tuya' => 'iot',
        'Shelly' => 'iot',
        'TP-Link' => 'network',
        'GL Technologies' => 'network',
        'Ubiquiti' => 'network',
        'Giga-Byte' => 'computer',
        'ASUSTek' => 'computer',
        'Micro-Star' => 'computer',
        'Intel Corporate' => 'computer',
        'Raspberry Pi' => 'server',
        'Brother' => 'printer',
        'Sonos' => 'speaker',
    ];

    public function __construct(
        private FreeboxClient $freebox,
        /** Instant présent, pour les tests ; sinon maintenant */
        private ?string $now = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $resolver
            ->setDefault('devices', [])
            ->setAllowedTypes('devices', 'array')
            ->setAllowedValues('devices', static function (array $devices): bool {
                foreach ($devices as $mac => $device) {
                    if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i', (string) $mac) || !\is_array($device) || [] !== array_diff(array_keys($device), ['type', 'name'])) {
                        return false;
                    }
                    if (isset($device['type']) && !\in_array($device['type'], self::TYPES, true)) {
                        return false;
                    }
                    if (isset($device['name']) && !\is_string($device['name'])) {
                        return false;
                    }
                }

                return true;
            })
            ->setNormalizer('devices', static fn (Options $o, array $devices): array => array_change_key_case($devices, \CASE_UPPER))
            ->setInfo('devices', 'par adresse MAC : { type: '.implode('|', self::TYPES).', name: … }, pour corriger ce que devine la Freebox')
            ->setDefault('rates_refresh', 5)
            ->setAllowedTypes('rates_refresh', 'int')
            ->setAllowedValues('rates_refresh', static fn (int $s): bool => $s >= 2)
            ->setInfo('rates_refresh', 'secondes entre deux lectures des débits')
            ->setDefault('keep_days', 7)
            ->setAllowedTypes('keep_days', 'int')
            ->setAllowedValues('keep_days', static fn (int $d): bool => $d >= 1)
            ->setInfo('keep_days', 'jours pendant lesquels un appareil déconnecté reste dans la liste');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return ['title' => $card->title ?? 'Réseau'];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $leases = $this->freebox->staticLeases();
        $since = (new \DateTimeImmutable($this->now ?? 'now'))->modify(\sprintf('-%d days', $card->options['keep_days']));
        $hosts = array_filter($this->freebox->hosts(), static fn (FreeboxHost $h): bool => $h->active || ($h->lastActivity ?? $since) > $since);

        $devices = array_map(function (FreeboxHost $h) use ($card, $leases): array {
            $config = $card->options['devices'][$h->mac] ?? [];

            return [
                'mac' => $h->mac,
                'name' => $config['name'] ?? $h->name,
                'vendor' => $h->vendor,
                'type' => $config['type'] ?? self::FREEBOX_TYPES[$h->type] ?? self::vendorType($h->vendor),
                'ip' => $h->ipv4,
                'expectedIp' => $leases[$h->mac] ?? null,
                'online' => $h->active,
                'lastSeen' => $h->lastActivity?->format(\DATE_ATOM),
                // Bit « administrée localement » : adresse privée (aléatoire) d'un téléphone ou d'un ordinateur.
                'randomMac' => (bool) (hexdec(substr($h->mac, 0, 2)) & 0x02),
            ];
        }, array_values($hosts));

        usort($devices, static fn (array $a, array $b): int => [!$a['online'], strtolower($a['name'] ?? $a['vendor'] ?? $a['mac'])]
            <=> [!$b['online'], strtolower($b['name'] ?? $b['vendor'] ?? $b['mac'])]);

        return ['devices' => $devices];
    }

    private static function vendorType(?string $vendor): string
    {
        foreach (self::VENDOR_TYPES as $prefix => $type) {
            if (null !== $vendor && str_starts_with(strtolower($vendor), strtolower($prefix))) {
                return $type;
            }
        }

        return 'other';
    }

    public function feeds(CardInstance $card): array
    {
        return ['rates' => $card->options['rates_refresh']];
    }

    public function fetchFeed(string $feed, CardInstance $card, Dashboard $dashboard): array
    {
        $c = $this->freebox->connection();

        return ['wan' => [
            'state' => $c->state,
            'media' => $c->media,
            'down' => $c->rateDown,
            'up' => $c->rateUp,
            'maxDown' => $c->bandwidthDown,
            'maxUp' => $c->bandwidthUp,
        ]];
    }
}
