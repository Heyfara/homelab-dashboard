<?php

namespace App\Tests\Source\Healthchecks;

use App\Source\Healthchecks\HealthchecksClient;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

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
     * @param array<string, list<string>>|null $reports rapports par check (slug), le plus récent d'abord ; sinon ceux de homelab()
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
     * @param array<string, mixed>             $query
     * @param array<string, list<string>>|null $reports rapports par check (slug), le plus récent d'abord
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
            return new MockResponse($list[\count($list) - intdiv((int) $m[2], 2)]);
        }

        // Le plus récent d'abord : chaque passage = un ping de début puis un ping de fin avec rapport.
        $pings = [['type' => 'log', 'n' => 2 * \count($list) + 1, 'body_url' => null]];
        foreach ($list as $i => $report) {
            $n = 2 * (\count($list) - $i);
            $json = json_decode($report, true);
            $failed = 'failed' === ($json['run']['state'] ?? $json['state'] ?? null);
            $pings[] = ['type' => $failed ? 'fail' : 'success', 'n' => $n, 'body_url' => \sprintf('https://healthchecks.test/api/v3/checks/u-%s/pings/%d/body', $m[1], $n)];
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

    public function testCheckNeverPinged(): void
    {
        $client = $this->client(static fn (string $path): MockResponse => '/api/v3/checks/' === $path
            ? new JsonMockResponse(['checks' => [['slug' => 'neuf', 'uuid' => 'u1']]])
            : new JsonMockResponse(['pings' => []]));

        self::assertSame([], $client->reports(self::URL, 'neuf', 1));
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
