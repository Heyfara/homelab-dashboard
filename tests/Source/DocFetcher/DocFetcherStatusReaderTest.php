<?php

namespace App\Tests\Source\DocFetcher;

use App\Source\DocFetcher\DocFetcherRun;
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
     * @param list<string|array{body: string, type: 'success'|'fail', date: string, duration: ?float}>|null $reports rapports de doc-fetcher, le plus récent d'abord ; sinon ceux de la fixture
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

    /**
     * doc-fetcher planté avant d'écrire son rapport (Paperless injoignable…) :
     * backup-run envoie la fin du log. Passage en échec, l'exception pour erreur.
     */
    public function testCrashBeforeReportIsFailedRun(): void
    {
        $status = self::reader([
            ['body' => self::TRACEBACK, 'type' => 'fail', 'date' => '2026-11-23T00:00:02+00:00', 'duration' => 2.0],
            ...\array_slice(HealthchecksClientTest::docFetcherReports(), 0, 1),
        ])->status(self::URL, 'doc-fetcher', 12);

        self::assertSame('chaque lundi à 7 h 30', $status->schedule);
        self::assertEquals(new DocFetcherRun(
            new \DateTimeImmutable('2026-11-23T00:00:00+00:00'),
            new \DateTimeImmutable('2026-11-23T00:00:02+00:00'),
            'failed',
            false,
            [],
            'RuntimeError: GET http://paperless.test/api/ui_settings/ : HTTP 400',
        ), $status->runs[0]);
        self::assertSame('action', $status->runs[1]->state);
        self::assertNull($status->runs[1]->error);
    }

    /** Sortie qui n'est pas un traceback Python (docker…) : sa dernière ligne. */
    public function testCrashWithoutPythonException(): void
    {
        $run = self::reader([
            ['body' => "docker: Error response from daemon: pull access denied.\nSee 'docker run --help'.\n\n", 'type' => 'fail', 'date' => '2026-11-23T00:00:02+00:00', 'duration' => null],
        ])->status(self::URL, 'doc-fetcher', 12)->runs[0];

        self::assertSame("See 'docker run --help'.", $run->error);
        self::assertEquals($run->finishedAt, $run->startedAt);
    }

    /** Erreur avant tout site (Paperless injoignable), écrite par doc-fetcher dans son rapport. */
    public function testRunLevelErrorInReport(): void
    {
        $run = self::reader(['{"run": {"started_at": "2026-11-23T01:00:01+01:00", "state": "failed", "sites": [], "error": "Paperless : HTTP 400"}}'])->status(self::URL, 'doc-fetcher', 12)->runs[0];

        self::assertSame(['failed', 'Paperless : HTTP 400'], [$run->state, $run->error]);
    }

    private const TRACEBACK = <<<'TXT'
        Traceback (most recent call last):
          File "/app/doc_fetcher.py", line 255, in main
            paperless = Paperless(config["paperless"]["url"], config["paperless"]["token"])
          File "/app/doc_fetcher.py", line 103, in request
            raise RuntimeError(f"{method} {url.split('?')[0]} : HTTP {e.code} {body}") from None
        RuntimeError: GET http://paperless.test/api/ui_settings/ : HTTP 400 
        <!doctype html>
        <html lang="en">
        <body>
          <h1>Bad Request (400)</h1><p></p>
        </body>
        </html>


        TXT;
}
