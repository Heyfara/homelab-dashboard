<?php

namespace App\Tests\Source\Healthchecks;

use App\Source\Healthchecks\HealthchecksCheck;
use App\Source\Healthchecks\HealthchecksClient;
use App\Source\Healthchecks\HealthchecksReport;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @phpstan-type EndPing array{body: string, type: 'success'|'fail', date: string, duration: ?float}
 */
final class HealthchecksClientTest extends TestCase
{
    private const URL = 'https://healthchecks.test';

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    private function client(?callable $respond = null, string $key = 'cle-hc'): HealthchecksClient
    {
        $respond ??= static fn (string $path, array $query): MockResponse => self::homelab($path, $query);

        return new HealthchecksClient(new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$url, $options];
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return $respond((string) parse_url($url, \PHP_URL_PATH), $query);
        }), $key);
    }

    /**
     * Client HTTP vers une instance Healthchecks fictive.
     *
     * @param array<string, list<string|EndPing>>|null $reports rapports par check (slug), le plus récent d'abord ; sinon ceux de homelab()
     */
    public static function httpClient(?array $reports = null): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use ($reports): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return self::homelab((string) parse_url($url, \PHP_URL_PATH), $query, $reports);
        });
    }

    /**
     * Instance Healthchecks fictive, réutilisée par FakeHomelab : par défaut la
     * sentinelle (rapport = tests/fixtures/backup-status.json) et doc-fetcher
     * (un rapport par passage de tests/fixtures/doc-fetcher-status.json).
     *
     * Un rapport est le contenu du ping de fin ; son type (succès, échec) se
     * déduit de l'état du passage, sauf ping décrit en entier (EndPing).
     *
     * @param array<string, mixed>                     $query
     * @param array<string, list<string|EndPing>>|null $reports rapports par check (slug), le plus récent d'abord
     */
    public static function homelab(string $path, array $query = [], ?array $reports = null): MockResponse
    {
        $reports ??= ['sentinel' => [(string) file_get_contents(__DIR__.'/../../fixtures/backup-status.json')], 'doc-fetcher' => self::docFetcherReports()];
        if ('/api/v3/checks/' === $path) {
            $slug = (string) ($query['slug'] ?? '');

            return new JsonMockResponse(['checks' => isset($reports[$slug]) ? [['slug' => $slug, 'uuid' => 'u-'.$slug]] : []]);
        }
        if (1 !== preg_match('#^/api/v3/checks/u-([^/]+)/pings/(?:(\d+)/body)?$#', $path, $m) || !isset($reports[$m[1]])) {
            throw new \LogicException(\sprintf('Requête Healthchecks non prévue : %s', $path));
        }
        $list = $reports[$m[1]];

        if (isset($m[2])) {
            // Ping n° 2 (N - i) : rapport i (0 = le plus récent)
            $report = $list[\count($list) - intdiv((int) $m[2], 2)];

            return new MockResponse(\is_string($report) ? $report : $report['body']);
        }

        // Le plus récent d'abord : chaque passage = un ping de début puis un ping de fin avec rapport.
        $pings = [['type' => 'log', 'n' => 2 * \count($list) + 1, 'body_url' => null]];
        foreach ($list as $i => $report) {
            $n = 2 * (\count($list) - $i);
            if (\is_string($report)) {
                $json = json_decode($report, true);
                $run = $json['run'] ?? $json;
                $report = [
                    'type' => 'failed' === ($run['state'] ?? null) ? 'fail' : 'success',
                    'date' => $run['finished_at'] ?? $run['started_at'] ?? '2026-10-19T07:31:12+02:00',
                    'duration' => null,
                ];
            }
            $pings[] = ['type' => $report['type'], 'n' => $n, 'date' => $report['date'], 'duration' => $report['duration'], 'body_url' => \sprintf('https://healthchecks.test/api/v3/checks/u-%s/pings/%d/body', $m[1], $n)];
            $pings[] = ['type' => 'start', 'n' => $n - 1, 'body_url' => null];
        }

        return new JsonMockResponse(['pings' => $pings]);
    }

    /** @return list<string> rapports de doc-fetcher, le plus récent d'abord */
    public static function docFetcherReports(): array
    {
        $status = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/doc-fetcher-status.json'), true, flags: \JSON_THROW_ON_ERROR);

        return array_map(static fn (array $run): string => json_encode(['job' => 'doc-fetcher', 'schedule' => $status['schedule'], 'run' => $run], \JSON_THROW_ON_ERROR), $status['runs']);
    }

    public function testLatestReport(): void
    {
        $reports = $this->client()->reports(self::URL, 'sentinel', 1);

        self::assertSame([(string) file_get_contents(__DIR__.'/../../fixtures/backup-status.json')], $reports);

        [$url, $options] = $this->requests[0];
        self::assertSame(self::URL.'/api/v3/checks/?slug=sentinel', $url);
        self::assertContains('X-Api-Key: cle-hc', $options['headers']);
    }

    public function testReportsNewestFirstSkippingPingsWithoutReport(): void
    {
        $reports = $this->client()->reports(self::URL, 'doc-fetcher', 3);

        self::assertSame(\array_slice(self::docFetcherReports(), 0, 3), $reports);
    }

    public function testFewerReportsThanAsked(): void
    {
        self::assertCount(4, $this->client()->reports(self::URL, 'doc-fetcher', 12));
    }

    public function testEndPingsWithDateAndOutcome(): void
    {
        $client = $this->client(static fn (string $path, array $query): MockResponse => self::homelab($path, $query, ['doc-fetcher' => [
            ['body' => "Traceback…\nRuntimeError: boum\n", 'type' => 'fail', 'date' => '2026-11-23T00:00:02+00:00', 'duration' => 1.5],
            ...\array_slice(self::docFetcherReports(), 0, 1),
        ]]));

        $pings = $client->endPings(self::URL, 'doc-fetcher', 12);

        self::assertEquals([
            new HealthchecksReport(new \DateTimeImmutable('2026-11-23T00:00:02+00:00'), true, 1.5, "Traceback…\nRuntimeError: boum\n"),
            new HealthchecksReport(new \DateTimeImmutable('2026-10-19T07:31:12+02:00'), false, null, self::docFetcherReports()[0]),
        ], $pings);
    }

    public function testCheckNeverPinged(): void
    {
        $client = $this->client(static fn (string $path): MockResponse => '/api/v3/checks/' === $path
            ? new JsonMockResponse(['checks' => [['slug' => 'neuf', 'uuid' => 'u1']]])
            : new JsonMockResponse(['pings' => []]));

        self::assertSame([], $client->reports(self::URL, 'neuf', 1));
    }

    public function testCheckStatus(): void
    {
        $client = $this->client(static fn (string $path, array $query): MockResponse => new JsonMockResponse(['checks' => [
            ['slug' => $query['slug'], 'status' => 'up', 'started' => false, 'last_ping' => '2026-11-01T17:01:08+01:00'],
        ]]));

        self::assertEquals(new HealthchecksCheck('up', new \DateTimeImmutable('2026-11-01T17:01:08+01:00'), false), $client->check(self::URL, 'scrub'));
        self::assertSame(self::URL.'/api/v3/checks/?slug=scrub', $this->requests[0][0]);
    }

    public function testCheckNeverPingedHasNoDate(): void
    {
        $client = $this->client(static fn (): MockResponse => new JsonMockResponse(['checks' => [['slug' => 'neuf', 'status' => 'new', 'started' => false, 'last_ping' => null]]]));

        self::assertEquals(new HealthchecksCheck('new', null, false), $client->check(self::URL, 'neuf'));
    }

    public function testStatusOfUnknownCheck(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Check « inconnu » absent de Healthchecks');

        $this->client()->check(self::URL, 'inconnu');
    }

    public function testUnknownCheck(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Check « inconnu » absent de Healthchecks');

        $this->client()->reports(self::URL, 'inconnu', 1);
    }

    public function testRejectedKey(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Clé API Healthchecks refusée (401)');

        $this->client(static fn (): MockResponse => new MockResponse('', ['http_code' => 401]))->reports(self::URL, 'sentinel', 1);
    }

    public function testMissingKey(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('HEALTHCHECKS_API_KEY');

        $this->client(key: '')->reports(self::URL, 'sentinel', 1);
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Healthchecks injoignable');

        $this->client(static fn (): MockResponse => new MockResponse('', ['http_code' => 502]))->reports(self::URL, 'sentinel', 1);
    }
}
