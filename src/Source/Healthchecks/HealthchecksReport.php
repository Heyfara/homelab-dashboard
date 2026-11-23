<?php

namespace App\Source\Healthchecks;

/**
 * Ping de fin d'un passage (succès ou échec) et son contenu : le rapport JSON
 * de la tâche, ou la fin de son log si elle a planté avant de l'écrire.
 */
final readonly class HealthchecksReport
{
    public function __construct(
        /** Réception du ping de fin */
        public \DateTimeImmutable $date,
        public bool $failed,
        /** Secondes depuis le ping de début ; null sans ping de début associé */
        public ?float $duration,
        public string $body,
    ) {
    }

    /** Début du passage : la date du ping de fin moins sa durée. */
    public function startedAt(): \DateTimeImmutable
    {
        return null === $this->duration ? $this->date : $this->date->modify(\sprintf('-%d microseconds', (int) round($this->duration * 1_000_000)));
    }
}
