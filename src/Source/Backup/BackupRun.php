<?php

namespace App\Source\Backup;

/** Un relevé passé de la sentinelle : l'état de chaque sauvegarde ce jour-là. */
final readonly class BackupRun
{
    /**
     * @param array<string, string> $states nom de la sauvegarde → ok, late, missing ou unreachable
     */
    public function __construct(
        public \DateTimeImmutable $checkedAt,
        public array $states,
    ) {
    }
}
