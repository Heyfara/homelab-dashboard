<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Host;
use App\Source\Container\ContainerState;
use App\Source\Container\ContainerStatusSource;
use App\Source\Container\DockerUptime;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Containers d'une machine : état, depuis quand, CPU et mémoire. Problèmes en
 * premier. Beszel ne connaît que les containers qui tournent : ceux que le
 * catalogue attend sur cette machine et qui manquent sont ajoutés, « arrêtés ».
 *
 *     - type: containers
 *       host: alpha
 *       hide: [beszel-agent]
 *       span: 2
 *       rows: 2
 */
#[AsCard('containers')]
final readonly class ContainersCard implements CardTypeInterface
{
    /** Ordre d'affichage : le plus grave d'abord. */
    private const SEVERITY = ['stopped' => 0, 'unhealthy' => 1, 'restarting' => 2, 'paused' => 3, 'starting' => 3, 'ok' => 4];

    public function __construct(
        private ContainerStatusSource $containers,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withBeszel = array_keys(array_filter($dashboard->hosts, static fn (Host $h): bool => null !== $h->beszelSystem));

        $resolver
            ->setRequired('host')
            ->setAllowedValues('host', array_map('strval', $withBeszel))
            ->setInfo('host', 'machine déclarée dans hosts, avec un système Beszel')
            ->setDefault('hide', [])
            ->setAllowedTypes('hide', 'string[]')
            ->setInfo('hide', 'containers à masquer, jokers * acceptés');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $host = $dashboard->hosts[$card->options['host']];

        return ['title' => $card->title ?? $host->name, 'system' => (string) $host->beszelSystem];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $hostName = $card->options['host'];
        $states = $this->containers->all($dashboard->hosts[$hostName]);

        foreach ($dashboard->services as $service) {
            if ($service->host === $hostName && null !== $service->container) {
                $states[$service->container] ??= ContainerState::stopped();
            }
        }

        $items = [];
        foreach ($states as $name => $state) {
            if ($this->hidden((string) $name, $card->options['hide'])) {
                continue;
            }
            $items[] = [
                'name' => (string) $name,
                'status' => $state->status,
                'health' => $state->health,
                'uptime' => null !== $state->uptime ? DockerUptime::short($state->uptime) : null,
                'cpu' => $state->cpu,
                'memory' => $state->memory,
            ];
        }

        usort($items, static fn (array $a, array $b): int => [self::severity($a), $a['name']] <=> [self::severity($b), $b['name']]);
        $problems = \count(array_filter($items, static fn (array $i): bool => self::severity($i) < self::SEVERITY['ok']));

        return [
            'containers' => $items,
            'counts' => [
                'total' => \count($items),
                'running' => \count(array_filter($items, static fn (array $i): bool => 'running' === $i['status'])),
                'problems' => $problems,
            ],
        ];
    }

    /**
     * @param array{status: string, health: ?string} $item
     */
    private static function severity(array $item): int
    {
        if ('running' !== $item['status']) {
            return self::SEVERITY[$item['status']] ?? 0;
        }

        return self::SEVERITY[$item['health'] ?? 'ok'] ?? self::SEVERITY['ok'];
    }

    /**
     * @param list<string> $patterns
     */
    private function hidden(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
