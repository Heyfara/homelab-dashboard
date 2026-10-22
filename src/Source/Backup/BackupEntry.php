<?php

namespace App\Source\Backup;

/** Une sauvegarde surveillée par la sentinelle, à l'heure de son passage. */
final readonly class BackupEntry
{
    public function __construct(
        public string $name,
        /** ok, late, missing ou unreachable */
        public string $state,
        /** Âge du dernier backup au passage de la sentinelle ; null s'il n'y en a pas */
        public ?float $ageHours,
        public float $maxHours,
        /** Nom du dernier fichier ou dossier de backup */
        public ?string $latest,
        public ?string $reason,
    ) {
    }
}
