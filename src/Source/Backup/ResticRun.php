<?php

namespace App\Source\Backup;

/**
 * Dernier passage de restic (rôle Ansible restic, restic-status.json joint par
 * la sentinelle) : sauvegarde vers le dépôt local, puis copie vers B2.
 * Les champs du résumé sont null si la sauvegarde a échoué avant la fin.
 */
final readonly class ResticRun
{
    public function __construct(
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $finishedAt,
        /** Durée totale du script : sauvegarde, nettoyage, copie B2 */
        public int $durationSeconds,
        /** Code retour du script, 0 si tout a réussi */
        public int $rc,
        public ?int $backupSeconds,
        /** Octets ajoutés au dépôt, avant compression */
        public ?int $dataAdded,
        public ?int $filesNew,
        public ?int $filesChanged,
        public ?int $filesProcessed,
        public ?int $bytesProcessed,
        public ?string $snapshotId,
        /** null : copie B2 pas tentée (sauvegarde en échec) */
        public ?int $copyRc,
        public ?int $copySeconds,
    ) {
    }
}
