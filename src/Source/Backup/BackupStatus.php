<?php

namespace App\Source\Backup;

final readonly class BackupStatus
{
    /**
     * @param list<BackupEntry> $entries
     * @param list<BackupRun>   $history relevés des derniers jours, du plus ancien au plus récent
     *                                   (vide si la sentinelle n'en garde pas)
     */
    public function __construct(
        public \DateTimeImmutable $checkedAt,
        public array $entries,
        public array $history = [],
        /** Dernier passage de restic, si la sentinelle le joint */
        public ?ResticRun $restic = null,
    ) {
    }
}
