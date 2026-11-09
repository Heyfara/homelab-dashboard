<?php

namespace App\Source\Dagu;

/** Un passage d'une tâche Dagu. */
final readonly class DaguRun
{
    public function __construct(
        /** ok, partial (une étape en échec, les autres faites), failed, running, queued ou unknown */
        public string $state,
        public \DateTimeImmutable $startedAt,
        /** null tant que le passage tourne */
        public ?\DateTimeImmutable $finishedAt,
        /** Lancé à la main (interface, API), pas par le planning */
        public bool $manual,
    ) {
    }
}
