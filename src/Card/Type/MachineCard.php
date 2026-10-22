<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Host;
use App\Source\Beszel\BeszelClient;
use App\Source\Beszel\BeszelStat;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Une machine suivie par Beszel : nom, IP, état, disques, et l'évolution du
 * processeur, de la mémoire, du réseau ou de la température.
 *
 *     - type: machine
 *       host: beta
 *       period: 24h
 *       charts: [cpu, memory, network]
 *       disk_names: { sdb1: RAID }
 *       span: 2
 *       rows: 2
 */
#[AsCard('machine')]
final readonly class MachineCard implements CardTypeInterface
{
    /** Période affichée → résolution Beszel qui la couvre. */
    private const PERIODS = ['1h' => '1m', '12h' => '10m', '24h' => '20m', '7d' => '120m', '30d' => '480m'];

    private const CHARTS = ['cpu', 'memory', 'network', 'temperature'];

    public function __construct(
        private BeszelClient $beszel,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withBeszel = array_keys(array_filter($dashboard->hosts, static fn (Host $h): bool => null !== $h->beszelSystem));

        $resolver
            ->setRequired('host')
            ->setAllowedValues('host', array_map('strval', $withBeszel))
            ->setInfo('host', 'machine déclarée dans hosts, avec un système Beszel')
            ->setDefault('period', '24h')
            ->setAllowedValues('period', array_keys(self::PERIODS))
            ->setDefault('charts', ['cpu', 'memory', 'network'])
            ->setAllowedTypes('charts', 'string[]')
            ->setAllowedValues('charts', static fn (array $charts): bool => [] === array_diff($charts, self::CHARTS))
            ->setDefault('disk_names', [])
            ->setAllowedTypes('disk_names', 'string[]')
            ->setInfo('disk_names', 'noms lisibles des disques supplémentaires : { sdb1: RAID }');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $host = $dashboard->hosts[$card->options['host']];

        return ['title' => $card->title ?? $host->name, 'system' => (string) $host->beszelSystem];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $systemName = (string) $dashboard->hosts[$card->options['host']]->beszelSystem;
        $system = $this->beszel->system($systemName);
        $stats = $this->beszel->stats($systemName, self::PERIODS[$card->options['period']]);
        $info = $system->info;
        $latest = [] !== $stats ? $stats[\count($stats) - 1]->values : [];

        $disks = [['name' => 'Système', 'percent' => $info['dp'] ?? null, 'used' => $latest['du'] ?? null, 'total' => $latest['d'] ?? null]];
        foreach ($info['efs'] ?? [] as $name => $percent) {
            $disks[] = [
                'name' => $card->options['disk_names'][$name] ?? $name,
                'percent' => $percent,
                'used' => $latest['efs'][$name]['du'] ?? null,
                'total' => $latest['efs'][$name]['d'] ?? null,
            ];
        }

        return [
            'machine' => [
                'name' => $system->name,
                'ip' => $system->host,
                'status' => $system->status,
                'uptime' => $info['u'] ?? null,
                'agent' => $info['v'] ?? null,
            ],
            'now' => [
                'cpu' => $info['cpu'] ?? null,
                'memory' => $info['mp'] ?? null,
                'load' => $info['la'] ?? [],
                'temperature' => $info['dt'] ?? null,
            ],
            'memory' => ['used' => $latest['mu'] ?? null, 'total' => $latest['m'] ?? null],
            'disks' => $disks,
            'series' => self::series($stats, $card->options['charts']),
        ];
    }

    /**
     * @param list<BeszelStat> $stats
     * @param list<string>     $charts
     *
     * @return array<string, list<mixed>>
     */
    private static function series(array $stats, array $charts): array
    {
        $series = ['at' => array_map(static fn (BeszelStat $s): string => $s->at->format(\DATE_ATOM), $stats)];
        $pick = static fn (callable $value): array => array_map(static fn (BeszelStat $s) => $value($s->values), $stats);

        if (\in_array('cpu', $charts, true)) {
            $series['cpu'] = $pick(static fn (array $v) => $v['cpu'] ?? null);
        }
        if (\in_array('memory', $charts, true)) {
            $series['memory'] = $pick(static fn (array $v) => $v['mp'] ?? null);
        }
        if (\in_array('network', $charts, true)) {
            $series['netSent'] = $pick(static fn (array $v) => $v['b'][0] ?? null);
            $series['netReceived'] = $pick(static fn (array $v) => $v['b'][1] ?? null);
        }
        if (\in_array('temperature', $charts, true)) {
            // Le capteur le plus chaud, comme l'aperçu de Beszel.
            $series['temperature'] = $pick(static fn (array $v) => [] !== ($v['t'] ?? []) ? max($v['t']) : null);
        }

        return $series;
    }
}
