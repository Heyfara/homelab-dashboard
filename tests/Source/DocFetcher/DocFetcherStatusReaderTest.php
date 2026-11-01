<?php

namespace App\Tests\Source\DocFetcher;

use App\Source\DocFetcher\DocFetcherSite;
use App\Source\DocFetcher\DocFetcherStatusReader;
use App\Source\DocFetcher\ImportedDocument;
use App\Source\Healthchecks\HealthchecksClient;
use App\Source\SourceUnavailableException;
use App\Tests\Source\Healthchecks\HealthchecksClientTest;
use PHPUnit\Framework\TestCase;

final class DocFetcherStatusReaderTest extends TestCase
{
    private const URL = 'https://healthchecks.test';

    /**
     * @param list<string>|null $reports rapports de doc-fetcher, le plus récent d'abord ; sinon ceux de la fixture
     */
    public static function reader(?array $reports = null): DocFetcherStatusReader
    {
        return new DocFetcherStatusReader(new HealthchecksClient(HealthchecksClientTest::httpClient(null === $reports ? null : ['doc-fetcher' => $reports]), 'cle-hc'));
    }

    public function testRunsNewestFirst(): void
    {
        $status = self::reader()->status(self::URL, 'doc-fetcher', 12);

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

    public function testLimitedNumberOfRuns(): void
    {
        self::assertCount(2, self::reader()->status(self::URL, 'doc-fetcher', 2)->runs);
    }

    public function testNoRunYet(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Aucun passage enregistré');

        self::reader([])->status(self::URL, 'doc-fetcher', 12);
    }

    public function testInvalidReport(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('n\'est pas un passage de doc-fetcher');

        self::reader(['{"services": []}'])->status(self::URL, 'doc-fetcher', 12);
    }

    public function testTruncatedReport(): void
    {
        $this->expectException(SourceUnavailableException::class);

        self::reader(['{"run": {'])->status(self::URL, 'doc-fetcher', 12);
    }

    /** Rapport sans enveloppe ni planification : le passage seul. */
    public function testBareRunWithOptionalFields(): void
    {
        $status = self::reader(['{"started_at": "2026-10-19T07:30:01+02:00", "state": "ok", "sites": [{"name": "paie", "state": "ok"}]}'])->status(self::URL, 'doc-fetcher', 12);

        self::assertNull($status->schedule);
        self::assertNull($status->runs[0]->finishedAt);
        self::assertFalse($status->runs[0]->manual);
        self::assertEquals([new DocFetcherSite('paie', 'ok', null, null, [], [])], $status->runs[0]->sites);
    }
}
