<?php

namespace App\Tests\Source\DocFetcher;

use App\Source\DocFetcher\DocFetcherSite;
use App\Source\DocFetcher\DocFetcherStatusReader;
use App\Source\DocFetcher\ImportedDocument;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;

final class DocFetcherStatusReaderTest extends TestCase
{
    public const FILE = __DIR__.'/../../fixtures/doc-fetcher-status.json';

    private function write(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'doc-fetcher');
        file_put_contents($file, $content);

        return $file;
    }

    public function testRunsNewestFirst(): void
    {
        $status = (new DocFetcherStatusReader())->status(self::FILE);

        self::assertSame('chaque lundi à 7 h 30', $status->schedule);
        self::assertCount(4, $status->runs);

        $run = $status->runs[0];
        self::assertEquals(new \DateTimeImmutable('2026-10-19T07:30:01+02:00'), $run->startedAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-19T07:31:12+02:00'), $run->finishedAt);
        self::assertSame(['action', false], [$run->state, $run->manual]);
        self::assertEquals([
            new DocFetcherSite('paie', 'ok', 12, 1, [new ImportedDocument(412, 'Bulletin septembre 2026', '2026-09-30')], []),
            new DocFetcherSite('impots', 'action', null, null, [], ['appareil non reconnu, code mail requis : lancer run.sh --site impots']),
        ], $run->sites);
        self::assertTrue($status->runs[1]->manual);
    }

    public function testMissingFileMeansNoRunYet(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Aucun passage enregistré');

        (new DocFetcherStatusReader())->status('/nulle/part/status.json');
    }

    public function testInvalidContent(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('n\'est pas un statut de doc-fetcher');

        (new DocFetcherStatusReader())->status($this->write('{"services": []}'));
    }

    public function testTruncatedJson(): void
    {
        $this->expectException(SourceUnavailableException::class);

        (new DocFetcherStatusReader())->status($this->write('{"runs": ['));
    }

    public function testOptionalFields(): void
    {
        $status = (new DocFetcherStatusReader())->status($this->write('{"runs": [{"started_at": "2026-10-19T07:30:01+02:00", "state": "ok", "sites": [{"name": "paie", "state": "ok"}]}]}'));

        self::assertNull($status->schedule);
        self::assertNull($status->runs[0]->finishedAt);
        self::assertFalse($status->runs[0]->manual);
        self::assertEquals([new DocFetcherSite('paie', 'ok', null, null, [], [])], $status->runs[0]->sites);
    }
}
