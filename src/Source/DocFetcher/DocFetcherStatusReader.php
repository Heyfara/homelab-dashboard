<?php

namespace App\Source\DocFetcher;

use App\Source\Healthchecks\HealthchecksClient;
use App\Source\SourceUnavailableException;

/**
 * Passages de doc-fetcher, lus dans Healthchecks : le rapport de chaque ping de
 * fin est le passage (`{"schedule": …, "run": {…}}`, ou le passage seul).
 * Format d'un passage : voir docs/configuration.md.
 */
final readonly class DocFetcherStatusReader
{
    public function __construct(
        private HealthchecksClient $healthchecks,
    ) {
    }

    /**
     * @param string $baseUrl adresse de Healthchecks
     * @param string $check   slug du check de doc-fetcher
     * @param int    $runs    nombre de passages à lire
     */
    public function status(string $baseUrl, string $check, int $runs): DocFetcherStatus
    {
        $reports = $this->healthchecks->reports($baseUrl, $check, $runs);
        if ([] === $reports) {
            throw new SourceUnavailableException(\sprintf('Aucun passage enregistré dans Healthchecks (check « %s »)', $check));
        }

        $schedule = null;
        $list = [];
        foreach ($reports as $report) {
            $json = json_decode($report, true);
            $run = \is_array($json['run'] ?? null) ? $json['run'] : $json;
            if (!\is_array($run) || !isset($run['started_at'], $run['state'])) {
                throw new SourceUnavailableException(\sprintf('Un rapport du check « %s » n\'est pas un passage de doc-fetcher (started_at, state)', $check));
            }
            $schedule ??= ($json['schedule'] ?? '') ?: null;
            $list[] = self::toRun($run);
        }

        return new DocFetcherStatus($schedule, $list);
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function toRun(array $r): DocFetcherRun
    {
        return new DocFetcherRun(
            new \DateTimeImmutable($r['started_at']),
            isset($r['finished_at']) ? new \DateTimeImmutable($r['finished_at']) : null,
            (string) $r['state'],
            (bool) ($r['manual'] ?? false),
            array_map(static fn (array $s): DocFetcherSite => new DocFetcherSite(
                (string) $s['name'],
                (string) $s['state'],
                isset($s['found']) ? (int) $s['found'] : null,
                isset($s['missing']) ? (int) $s['missing'] : null,
                array_map(static fn (array $d): ImportedDocument => new ImportedDocument(
                    (int) $d['id'],
                    (string) $d['title'],
                    ($d['created'] ?? '') ?: null,
                ), array_values($s['imported'] ?? [])),
                array_map('strval', array_values($s['errors'] ?? [])),
            ), array_values($r['sites'] ?? [])),
        );
    }
}
