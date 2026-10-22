<?php

namespace App\Source\DocFetcher;

use App\Source\SourceUnavailableException;

/**
 * Lit le statut de doc-fetcher, un fichier JSON monté dans le container (le
 * script tourne sur la même machine). Format : voir docs/configuration.md.
 */
final readonly class DocFetcherStatusReader
{
    public function status(string $file): DocFetcherStatus
    {
        if (!is_file($file)) {
            throw new SourceUnavailableException(\sprintf('Aucun passage enregistré (%s absent)', $file));
        }
        $content = @file_get_contents($file);
        if (false === $content) {
            throw new SourceUnavailableException(\sprintf('%s illisible (droits du dossier monté ?)', $file));
        }
        try {
            $json = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SourceUnavailableException(\sprintf('%s : JSON invalide (%s)', $file, $e->getMessage()), previous: $e);
        }
        if (!\is_array($json) || !\is_array($json['runs'] ?? null)) {
            throw new SourceUnavailableException(\sprintf('%s n\'est pas un statut de doc-fetcher (runs)', $file));
        }

        return new DocFetcherStatus(
            ($json['schedule'] ?? '') ?: null,
            array_map(self::toRun(...), array_values($json['runs'])),
        );
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
