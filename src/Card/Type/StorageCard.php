<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Host;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Beszel\BeszelClient;
use App\Source\Beszel\BeszelSmartDevice;
use App\Source\Beszel\BeszelStat;
use App\Source\Healthchecks\HealthchecksClient;
use App\Source\SourceUnavailableException;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Remplissage d'un volume de stockage suivi par Beszel (un des EXTRA_FILESYSTEMS
 * de l'agent) : place utilisée et libre, rythme de croissance et date estimée
 * où il sera plein. En option : état du pool, disques (SMART de Beszel) et
 * dernier scrub (check Healthchecks).
 *
 *     - type: storage
 *       host: beta
 *       filesystem: NAS     # nom donné par Beszel (EXTRA_FILESYSTEMS=/mnt/raid__NAS)
 *       period: 30d
 *       pool: nas           # facultatif : état du pool btrfs/ZFS (étiquette du pool)
 *       disks: [WW6B…, WD-…]  # facultatif : numéros de série des disques du pool
 *       healthchecks: healthchecks  # facultatif, avec scrub_check
 *       scrub_check: nas-scrub
 *       span: 2
 */
#[AsCard('storage')]
final readonly class StorageCard implements CardTypeInterface
{
    /** Période affichée → résolution Beszel qui la couvre. En deçà, pas de tendance. */
    private const PERIODS = ['7d' => '120m', '30d' => '480m'];

    /** Recul minimal (jours) pour annoncer un rythme de croissance. */
    private const MIN_TREND_DAYS = 2;

    /** Écart toléré entre le total d'une tranche et le total actuel. */
    private const TOTAL_TOLERANCE = 0.01;

    public function __construct(
        private BeszelClient $beszel,
        private HealthchecksClient $healthchecks,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withBeszel = array_keys(array_filter($dashboard->hosts, static fn (Host $h): bool => null !== $h->beszelSystem));

        $resolver
            ->setRequired(['host', 'filesystem'])
            ->setAllowedValues('host', array_map('strval', $withBeszel))
            ->setInfo('host', 'machine déclarée dans hosts, avec un système Beszel')
            ->setAllowedTypes('filesystem', 'string')
            ->setInfo('filesystem', 'nom du volume dans Beszel (EXTRA_FILESYSTEMS de l\'agent)')
            ->setDefault('period', '30d')
            ->setAllowedValues('period', array_keys(self::PERIODS))
            ->setDefault('pool', null)
            ->setAllowedTypes('pool', ['null', 'string'])
            ->setInfo('pool', 'étiquette du pool btrfs ou ZFS dont afficher l\'état (ONLINE, DEGRADED…)')
            ->setDefault('disks', [])
            ->setAllowedTypes('disks', 'string[]')
            ->setInfo('disks', 'numéros de série des disques à afficher (SMART suivi par Beszel), dans l\'ordre')
            ->setDefault('healthchecks', null)
            ->setAllowedTypes('healthchecks', ['null', 'string'])
            ->setAllowedValues('healthchecks', static fn (?string $s): bool => null === $s || null !== ($dashboard->services[$s] ?? null)?->url)
            ->setInfo('healthchecks', 'service Healthchecks du catalogue, qui reçoit les pings du scrub')
            ->setDefault('scrub_check', null)
            ->setAllowedTypes('scrub_check', ['null', 'string'])
            ->setInfo('scrub_check', 'slug du check du scrub dans Healthchecks (avec healthchecks)')
            ->setNormalizer('scrub_check', static function (Options $options, ?string $check): ?string {
                if (null !== $check && null === $options['healthchecks']) {
                    throw new InvalidOptionsException('scrub_check demande l\'option healthchecks');
                }

                return $check;
            });
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $host = $dashboard->hosts[$card->options['host']];

        return ['title' => $card->title ?? $card->options['filesystem'], 'system' => (string) $host->beszelSystem];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $systemName = (string) $dashboard->hosts[$card->options['host']]->beszelSystem;
        $fs = $card->options['filesystem'];
        $system = $this->beszel->system($systemName);
        $known = $system->info['efs'] ?? [];
        if (!\array_key_exists($fs, $known)) {
            throw new SourceUnavailableException(\sprintf('Beszel ne suit pas « %s » sur %s (suivis : %s)', $fs, $system->name, implode(', ', array_keys($known)) ?: 'aucun'));
        }

        $stats = $this->beszel->stats($systemName, self::PERIODS[$card->options['period']]);
        // Les tranches longues sont des moyennes : celle où le volume apparaît (ou
        // l'agent redémarre) compte des zéros. Valeurs actuelles = la dernière minute.
        $minutes = $this->beszel->stats($systemName, '1m');
        $lastMinute = [] !== $minutes ? $minutes[\count($minutes) - 1]->values : [];
        $latest = $lastMinute['efs'][$fs] ?? [];
        $usedNow = isset($latest['du']) ? (float) $latest['du'] : null;
        $total = isset($latest['d']) ? (float) $latest['d'] : null;
        $free = null !== $usedNow && null !== $total ? $total - $usedNow : null;
        $used = array_map(static fn (BeszelStat $s): ?float => self::usedIfComplete($s->values['efs'][$fs] ?? [], $total), $stats);

        return [
            'percent' => $known[$fs],
            'used' => $usedNow,
            'total' => $total,
            'free' => $free,
            'health' => null === $card->options['pool'] ? null : self::health($lastMinute['z'] ?? [], $card->options['pool']),
            'growth' => self::growth($stats, $used, $free),
            'disks' => [] === $card->options['disks'] ? [] : self::disks($this->beszel->smartDevices($systemName), $card->options['disks']),
            'scrub' => null === $card->options['scrub_check'] ? null : $this->scrub($dashboard->services[$card->options['healthchecks']], $card->options['scrub_check']),
        ];
    }

    /**
     * Disques demandés, dans l'ordre de la configuration ; absent de Beszel = MISSING.
     *
     * @param array<string, BeszelSmartDevice> $devices
     * @param list<string>                     $serials
     *
     * @return list<array<string, mixed>>
     */
    private static function disks(array $devices, array $serials): array
    {
        return array_map(static function (string $serial) use ($devices): array {
            $d = $devices[$serial] ?? null;

            return [
                'serial' => $serial,
                'model' => $d?->model,
                'capacity' => $d?->capacity,
                'state' => $d?->state ?? 'MISSING',
                'temperature' => $d?->temperature,
                'hours' => $d?->powerOnHours,
                'counters' => $d->counters ?? [],
            ];
        }, $serials);
    }

    /**
     * Dernier passage du scrub. Healthchecks injoignable : « unavailable »,
     * sans masquer le reste de la carte.
     *
     * @return array{status: string, at: ?string, running: bool}
     */
    private function scrub(ServiceDefinition $healthchecks, string $slug): array
    {
        try {
            $check = $this->healthchecks->check($healthchecks->check ?? (string) $healthchecks->url, $slug);
        } catch (SourceUnavailableException) {
            return ['status' => 'unavailable', 'at' => null, 'running' => false];
        }

        return ['status' => $check->status, 'at' => $check->lastPing?->format(\DATE_ATOM), 'running' => $check->started];
    }

    /**
     * État du pool d'après Beszel : ONLINE, DEGRADED (disque manquant) ou UNKNOWN.
     * Pool absent de la dernière mesure : UNKNOWN.
     *
     * @param array<string, array{n?: string, h?: string}> $pools clés « b:<uuid> » (btrfs) ou nom (ZFS)
     */
    private static function health(array $pools, string $name): string
    {
        foreach ($pools as $key => $pool) {
            if ($name === ($pool['n'] ?? $key)) {
                return $pool['h'] ?? 'UNKNOWN';
            }
        }

        return 'UNKNOWN';
    }

    /**
     * Place utilisée d'une tranche, sauf si son total s'écarte de plus de 1 %
     * du total actuel : tranche partielle, ou capacité différente (disque ajouté).
     *
     * @param array{d?: float, du?: float} $efs
     */
    private static function usedIfComplete(array $efs, ?float $total): ?float
    {
        if (!isset($efs['du'], $efs['d'])) {
            return null;
        }
        if (null !== $total && abs($efs['d'] - $total) > $total * self::TOTAL_TOLERANCE) {
            return null;
        }

        return (float) $efs['du'];
    }

    /**
     * Pente de la droite des moindres carrés (Go/jour) sur les mesures connues.
     *
     * @param list<BeszelStat> $stats
     * @param list<?float>     $used
     *
     * @return array{perDay: float, fullInDays: ?float, days: int}|null
     */
    private static function growth(array $stats, array $used, ?float $free): ?array
    {
        $points = [];
        foreach ($stats as $i => $stat) {
            if (null !== $used[$i]) {
                $points[] = [$stat->at->getTimestamp() / 86400, $used[$i]];
            }
        }
        if (\count($points) < 2) {
            return null;
        }
        $days = (int) floor($points[\count($points) - 1][0] - $points[0][0]);
        if ($days < self::MIN_TREND_DAYS) {
            return null;
        }

        $n = \count($points);
        $meanX = array_sum(array_column($points, 0)) / $n;
        $meanY = array_sum(array_column($points, 1)) / $n;
        $covariance = $variance = 0.0;
        foreach ($points as [$x, $y]) {
            $covariance += ($x - $meanX) * ($y - $meanY);
            $variance += ($x - $meanX) ** 2;
        }
        $perDay = $covariance / $variance;

        return [
            'perDay' => $perDay,
            'fullInDays' => $perDay > 0 && null !== $free ? $free / $perDay : null,
            'days' => $days,
        ];
    }
}
