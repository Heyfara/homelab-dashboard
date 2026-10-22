<?php

namespace App\Source\Container;

use App\Dashboard\Model\Host;
use App\Source\Beszel\BeszelClient;
use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * État des containers d'après Beszel. L'agent ne remonte que les containers
 * qui tournent : un container absent, ou dont la fiche n'est plus mise à jour,
 * est arrêté (ou supprimé).
 */
#[AsAlias(ContainerStatusSource::class)]
final readonly class BeszelContainerSource implements ContainerStatusSource
{
    /** Retard toléré sur la fiche la plus récente du système (l'agent écrit chaque minute). */
    private const STALE_AFTER = 120;

    private const HEALTH = [1 => 'starting', 2 => 'healthy', 3 => 'unhealthy'];

    public function __construct(
        private BeszelClient $beszel,
    ) {
    }

    public function status(Host $host, string $container): ContainerState
    {
        return $this->all($host)[$container] ?? ContainerState::stopped();
    }

    public function all(Host $host): array
    {
        $systemName = $host->beszelSystem ?? throw new \LogicException(\sprintf('La machine « %s » n\'a pas de système Beszel.', $host->name));

        $system = $this->beszel->systems()[$systemName] ?? null;
        if (null !== $system && 'up' !== $system->status) {
            throw new SourceUnavailableException(\sprintf('Beszel ne reçoit plus les données de %s (état : %s)', $systemName, $system->status));
        }

        $containers = $this->beszel->containers($systemName);
        $freshest = max([0, ...array_map(static fn ($c) => $c->updated, $containers)]);

        $states = [];
        foreach ($containers as $name => $record) {
            $states[$name] = $freshest - $record->updated > self::STALE_AFTER
                ? ContainerState::stopped()
                : new ContainerState(
                    self::parseStatus($record->status),
                    self::HEALTH[$record->health] ?? null,
                    $record->status,
                    $record->cpu,
                    $record->memory,
                );
        }

        return $states;
    }

    private static function parseStatus(string $text): string
    {
        return match (true) {
            str_starts_with($text, 'Restarting') => 'restarting',
            str_contains($text, '(Paused)') => 'paused',
            str_starts_with($text, 'Up') => 'running',
            default => 'stopped',
        };
    }
}
