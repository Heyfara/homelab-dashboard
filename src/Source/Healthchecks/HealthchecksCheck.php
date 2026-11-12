<?php

namespace App\Source\Healthchecks;

/**
 * État d'un check Healthchecks d'après sa fiche (pas ses pings).
 */
final readonly class HealthchecksCheck
{
    public function __construct(
        /** new (jamais pingé), up, grace, down ou paused */
        public string $status,
        /** Dernier ping reçu, quel qu'il soit (début, succès, échec) */
        public ?\DateTimeImmutable $lastPing,
        /** Un passage est en cours (ping de début reçu, pas encore de fin) */
        public bool $started,
    ) {
    }
}
