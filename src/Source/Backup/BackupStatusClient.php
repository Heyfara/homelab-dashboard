<?php

namespace App\Source\Backup;

use App\Source\Healthchecks\HealthchecksClient;
use App\Source\SourceUnavailableException;

/**
 * Statut écrit chaque matin par la sentinelle de sauvegarde du homelab
 * (rôle Ansible backup-sentinel, backup-freshness) : âge du dernier backup de
 * chaque service, comparé à un seuil, et les relevés des jours précédents. La
 * sentinelle l'envoie comme rapport de son ping Healthchecks.
 */
final readonly class BackupStatusClient
{
    public function __construct(
        private HealthchecksClient $healthchecks,
    ) {
    }

    /**
     * @param string $baseUrl adresse de Healthchecks
     * @param string $check   slug du check de la sentinelle
     */
    public function status(string $baseUrl, string $check): BackupStatus
    {
        $report = $this->healthchecks->reports($baseUrl, $check, 1)[0] ?? null;
        if (null === $report) {
            throw new SourceUnavailableException(\sprintf('Aucun rapport de la sentinelle dans Healthchecks (check « %s »)', $check));
        }
        $json = json_decode($report, true);
        if (!\is_array($json) || !isset($json['generated_at'], $json['services']) || !\is_array($json['services'])) {
            throw new SourceUnavailableException(\sprintf('Le rapport du check « %s » n\'est pas un statut de sentinelle (generated_at, services)', $check));
        }

        return new BackupStatus(new \DateTimeImmutable($json['generated_at']), array_map(static fn (array $s): BackupEntry => new BackupEntry(
            (string) $s['name'],
            (string) ($s['state'] ?? 'missing'),
            isset($s['age_hours']) ? (float) $s['age_hours'] : null,
            (float) ($s['max_hours'] ?? 30),
            ($s['latest'] ?? '') ?: null,
            ($s['reason'] ?? '') ?: null,
        ), array_values($json['services'])), array_map(static fn (array $r): BackupRun => new BackupRun(
            new \DateTimeImmutable($r['generated_at']),
            array_map('strval', $r['states'] ?? []),
        ), array_values(\is_array($json['history'] ?? null) ? $json['history'] : [])), \is_array($json['restic'] ?? null) ? self::toRestic($json['restic']) : null);
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function toRestic(array $r): ResticRun
    {
        $int = static fn (mixed $v): ?int => null === $v ? null : (int) $v;
        $backup = \is_array($r['backup'] ?? null) ? $r['backup'] : [];
        $copy = \is_array($r['b2_copy'] ?? null) ? $r['b2_copy'] : [];

        return new ResticRun(
            new \DateTimeImmutable($r['started_at']),
            new \DateTimeImmutable($r['finished_at']),
            (int) ($r['duration_seconds'] ?? 0),
            (int) ($r['rc'] ?? 0),
            $int($backup['seconds'] ?? null),
            $int($backup['data_added'] ?? null),
            $int($backup['files_new'] ?? null),
            $int($backup['files_changed'] ?? null),
            $int($backup['total_files_processed'] ?? null),
            $int($backup['total_bytes_processed'] ?? null),
            $backup['snapshot_id'] ?? null,
            $int($copy['rc'] ?? null),
            $int($copy['seconds'] ?? null),
        );
    }
}
