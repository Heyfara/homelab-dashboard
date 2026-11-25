<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardFeedsInterface;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Network\AccessPoint;
use App\Network\Placement;
use App\Source\Beszel\BeszelClient;
use App\Source\Freebox\FreeboxClient;
use App\Source\Freebox\FreeboxHost;
use App\Source\OpenWrt\OpenWrtProbe;
use App\Network\ApSnapshot;
use App\Source\SourceUnavailableException;
use App\Source\Tplink\TplinkClient;
use App\Storage\JsonFiles;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Onglet Réseau : appareils vus par la Freebox (récemment ou en ce moment),
 * placés d'après ce que voient les points d'accès OpenWrt (voir Placement), et
 * débits (flux `rates`, toutes les 5 s) : Internet, ports et radios des points
 * d'accès. Prévue pour occuper seule son onglet : sa hauteur suit son contenu.
 *
 *     - type: network
 *       access_points:                 # facultatif : points d'accès lus par SSH (OpenWrt) ou HTTP (TP-Link)
 *         - { id: flint, address: 192.168.1.175, zone: Bureau }
 *         - { id: archer, kind: tplink, address: 192.168.1.133, zone: Étage }
 *       devices:                       # facultatif : type ou nom par adresse MAC
 *         "AA:BB:CC:DD:EE:FF": { type: server, name: alpha }
 *
 * La dernière position de chaque appareil est gardée dans HISTORY_DATA_DIR
 * (network-positions.json).
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

    private JsonFiles $files;
    private \Closure $clock;

    /**
     * @param ?\Closure(): float $clock instant présent (s), pour les tests
     */
    public function __construct(
        private FreeboxClient $freebox,
        private OpenWrtProbe $probe,
        private TplinkClient $tplink,
        private BeszelClient $beszel,
        private CacheItemPoolInterface $cache,
        #[Autowire('%env(default:history_data_default_dir:HISTORY_DATA_DIR)%')]
        string $dataDir,
        ?\Closure $clock = null,
    ) {
        $this->files = new JsonFiles($dataDir);
        $this->clock = $clock ?? static fn (): float => microtime(true);
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
            ->setInfo('keep_days', 'jours pendant lesquels un appareil déconnecté reste dans la liste')
            ->setOptions('access_points', static function (OptionsResolver $ap): void {
                $ap->setPrototype(true)
                    ->setRequired(['id', 'address', 'zone'])
                    ->setAllowedTypes('id', 'string')
                    ->setAllowedValues('id', static fn (string $id): bool => 1 === preg_match('/^[a-z0-9_-]+$/', $id))
                    ->setAllowedTypes('address', 'string')
                    ->setAllowedTypes('zone', 'string')
                    ->setDefault('kind', 'openwrt')
                    ->setAllowedValues('kind', ['openwrt', 'tplink'])
                    ->setInfo('kind', 'openwrt : lu par SSH (script dashboard-probe) ; tplink : Archer C6 lu comme son interface web')
                    ->setDefault('uplink', 'eth1')
                    ->setAllowedTypes('uplink', 'string')
                    ->setInfo('uplink', 'port du bridge relié au switch');
            })
            ->setAllowedValues('access_points', static fn (array $aps): bool => \count($aps) === \count(array_unique(array_column($aps, 'id'))))
            ->setInfo('access_points', 'points d’accès : { id, address, zone, kind: openwrt|tplink, uplink: eth1 }')
            ->setDefault('wired_zone', 'Baie')
            ->setAllowedTypes('wired_zone', 'string')
            ->setInfo('wired_zone', 'zone des appareils câblés sur le switch')
            ->setDefault('memory', 300)
            ->setAllowedTypes('memory', 'int')
            ->setAllowedValues('memory', static fn (int $s): bool => $s >= 0)
            ->setInfo('memory', 'secondes pendant lesquelles un appareil garde son point d’accès après l’avoir quitté');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return ['title' => $card->title ?? 'Réseau'];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $now = (int) ($this->clock)();
        $leases = $this->freebox->staticLeases();
        $since = (new \DateTimeImmutable('@'.$now))->modify(\sprintf('-%d days', $card->options['keep_days']));
        $hosts = array_values(array_filter($this->freebox->hosts(), static fn (FreeboxHost $h): bool => $h->active || ($h->lastActivity ?? $since) > $since));

        $aps = self::accessPoints($card);
        [$snapshots, $errors] = $this->snapshots($aps);
        $addresses = array_map(static fn (AccessPoint $ap): string => $ap->address, $aps);
        $infrastructure = array_values(array_map(static fn (FreeboxHost $h): string => $h->mac, array_filter($hosts, static fn (FreeboxHost $h): bool => \in_array($h->ipv4, $addresses, true))));
        $placement = new Placement($card->options['wired_zone'], $card->options['memory'], $card->options['keep_days'] * 86400);
        $places = [];
        $memory = [];
        $this->files->update('network-positions', static function (array $previous) use ($placement, $hosts, $aps, $snapshots, $infrastructure, $now, &$places, &$memory): array {
            [$places, $memory] = $placement->place(array_map(static fn (FreeboxHost $h): string => $h->mac, $hosts), $aps, $snapshots, $infrastructure, $previous, $now);

            return $memory;
        });

        $devices = array_map(function (FreeboxHost $h) use ($card, $leases, $places, $memory, $now): array {
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
                'place' => null === ($p = $places[$h->mac] ?? null) ? null
                    : array_replace($p, ['seenAt' => null === $p['seenAt'] ? null : (new \DateTimeImmutable('@'.$p['seenAt']))->format(\DATE_ATOM)]),
                'firstSeen' => $h->firstActivity?->format(\DATE_ATOM),
                // Changements de point d'accès et retours après coupure, sur la dernière heure.
                'changes' => \count(array_filter($memory[$h->mac]['events'] ?? [], static fn (int $t): bool => $t > $now - Placement::EVENTS_WINDOW)),
            ];
        }, $hosts);

        usort($devices, static fn (array $a, array $b): int => [!$a['online'], strtolower($a['name'] ?? $a['vendor'] ?? $a['mac'])]
            <=> [!$b['online'], strtolower($b['name'] ?? $b['vendor'] ?? $b['mac'])]);

        return [
            'devices' => $devices,
            'accessPoints' => array_values(array_map(static fn (AccessPoint $ap): array => [
                'id' => $ap->id,
                'zone' => $ap->zone,
                'error' => $errors[$ap->id] ?? null,
                'uplink' => $ap->uplink,
                'radios' => array_map(Placement::band(...), $snapshots[$ap->id]?->radios ?? []),
                // L'appareil du point d'accès lui-même, retrouvé par son IP : il mène sa zone sur le schéma.
                'device' => array_values(array_filter($hosts, static fn (FreeboxHost $h): bool => $h->ipv4 === $ap->address))[0]->mac ?? null,
                'kind' => $ap->kind,
            ], $aps)),
            'wiredZone' => $card->options['wired_zone'],
        ];
    }

    /**
     * @return array<string, AccessPoint>
     */
    private static function accessPoints(CardInstance $card): array
    {
        $aps = [];
        foreach ($card->options['access_points'] as $ap) {
            $aps[$ap['id']] = new AccessPoint($ap['id'], $ap['address'], $ap['zone'], $ap['uplink'], $ap['kind']);
        }

        return $aps;
    }

    /**
     * Un point d'accès injoignable ne fait pas tomber la carte : il est signalé.
     *
     * @param array<string, AccessPoint> $aps
     *
     * @return array{array<string, ?ApSnapshot>, array<string, string>}
     */
    private function snapshots(array $aps): array
    {
        $snapshots = $errors = [];
        foreach ($aps as $id => $ap) {
            try {
                $snapshots[$id] = 'tplink' === $ap->kind ? $this->tplink->snapshot($ap->address) : $this->probe->snapshot($ap->address);
            } catch (SourceUnavailableException $e) {
                $snapshots[$id] = null;
                $errors[$id] = $e->getMessage();
            }
        }

        return [$snapshots, $errors];
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
        // Seuls les points d'accès OpenWrt donnent les compteurs de leurs ports.
        $aps = array_filter(self::accessPoints($card), static fn (AccessPoint $ap): bool => 'openwrt' === $ap->kind);
        [$snapshots, $errors] = $this->snapshots($aps);
        $rates = [];
        foreach ($aps as $id => $ap) {
            $rates[$id] = ['error' => $errors[$id] ?? null, 'ports' => null === $snapshots[$id] ? [] : $this->portRates($ap, $snapshots[$id])];
        }

        return ['accessPoints' => $rates, 'hosts' => $this->hostRates(), 'wan' => [
            'state' => $c->state,
            'media' => $c->media,
            'down' => $c->rateDown,
            'up' => $c->rateUp,
            'maxDown' => $c->bandwidthDown,
            'maxUp' => $c->bandwidthUp,
        ]];
    }

    /**
     * Débit réseau des machines suivies par Beszel (moyenne de la dernière
     * minute), par IP de leur agent : vu de la machine, `down` = reçu. Beszel
     * absent ou en panne : rien, sans faire tomber les autres débits.
     *
     * @return array<string, array{down: int, up: int}>
     */
    private function hostRates(): array
    {
        $rates = [];
        try {
            foreach ($this->beszel->systems() as $system) {
                if ('up' !== $system->status || '' === $system->host) {
                    continue;
                }
                $stats = $this->beszel->stats($system->name, '1m');
                $b = [] !== $stats ? ($stats[\count($stats) - 1]->values['b'] ?? null) : null;
                if (\is_array($b) && isset($b[0], $b[1])) {
                    $rates[$system->host] = ['down' => (int) $b[1], 'up' => (int) $b[0]];
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $rates;
    }

    /**
     * Débit de chaque port depuis le relevé précédent, vu de l'appareil branché :
     * `down` = vers lui. Pour le port amont, `down` = ce qui arrive du switch.
     *
     * @return array<string, array{down: int, up: int}>
     */
    private function portRates(AccessPoint $ap, ApSnapshot $s): array
    {
        $item = $this->cache->getItem('network.counters.'.hash('xxh128', $ap->id.'|'.$ap->address));
        $previous = $item->isHit() ? $item->get() : null;
        $item->set(['at' => $s->readAt, 'ports' => $s->ports])->expiresAfter(300);
        $this->cache->save($item);

        $dt = \is_array($previous) ? $s->readAt - $previous['at'] : 0.0;
        if ($dt <= 0) {
            return [];
        }
        $rates = [];
        foreach ($s->ports as $port => [$rx, $tx]) {
            [$prx, $ptx] = $previous['ports'][$port] ?? [null, null];
            if (null === $prx || $rx < $prx || $tx < $ptx) {
                continue;
            }
            [$in, $out] = [(int) round(($rx - $prx) / $dt), (int) round(($tx - $ptx) / $dt)];
            $rates[$port] = $port === $ap->uplink ? ['down' => $in, 'up' => $out] : ['down' => $out, 'up' => $in];
        }

        return $rates;
    }
}
