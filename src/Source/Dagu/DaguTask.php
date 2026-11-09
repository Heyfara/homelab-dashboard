<?php

namespace App\Source\Dagu;

/** Une tâche (DAG) de Dagu, avec ses derniers passages. */
final readonly class DaguTask
{
    /**
     * @param list<string>  $schedules expressions cron
     * @param list<DaguRun> $runs      le plus récent d'abord
     */
    public function __construct(
        public string $name,
        /** Description sans ce que le rôle Ansible y ajoute (hôtes, lien du code) */
        public ?string $description,
        public array $schedules,
        public ?\DateTimeImmutable $nextRun,
        public bool $suspended,
        public array $runs,
    ) {
    }
}
