<?php

namespace App\Source\Dagu;

/** Un passage d'une tâche Dagu, avec ce qu'il faut pour le suivre ou l'arrêter. */
final readonly class DaguRunDetail
{
    public function __construct(
        public string $id,
        /** ok, partial, failed, running, queued ou unknown (voir DaguRun) */
        public string $state,
        public \DateTimeImmutable $startedAt,
        /** null tant que le passage tourne */
        public ?\DateTimeImmutable $finishedAt,
        /** @var array<string, string> paramètres du lancement */
        public array $params,
    ) {
    }
}
