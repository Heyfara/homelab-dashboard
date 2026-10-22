<?php

namespace App\Tests\Source\Qbittorrent;

use App\Source\Qbittorrent\QbittorrentClient;
use App\Source\Qbittorrent\QbittorrentTorrent;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QbittorrentClientTest extends TestCase
{
    private const BASE = 'http://10.0.0.4:8080';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    /**
     * @param callable(string, array<string, mixed>): MockResponse $respond
     */
    private function client(callable $respond, string $password = 'secret'): QbittorrentClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$method, $url, $options];

            return $respond($url, $options);
        });

        return new QbittorrentClient($http, $this->cache, 'admin', $password);
    }

    private static function loginOk(string $cookie = 'QBT_SID_8080=sid-1'): MockResponse
    {
        return new MockResponse('Ok.', ['response_headers' => ['Set-Cookie' => $cookie.'; HttpOnly; path=/; SameSite=Strict']]);
    }

    private static function maindata(): JsonMockResponse
    {
        return new JsonMockResponse([
            'server_state' => ['alltime_dl' => 5_000_000_000_000, 'alltime_ul' => 12_000_000_000_000, 'dl_info_speed' => 1_500_000, 'up_info_speed' => 250_000, 'global_ratio' => '2.40'],
            'torrents' => [
                'h1' => ['name' => 'debian-13.iso', 'size' => 700_000_000, 'progress' => 1, 'state' => 'stalledUP', 'added_on' => 1792300000, 'completion_on' => 1792300600, 'dlspeed' => 0, 'upspeed' => 1000],
                'h2' => ['name' => 'film.mkv', 'size' => 4_000_000_000, 'progress' => 0.42, 'state' => 'downloading', 'added_on' => 1792400000, 'completion_on' => -1, 'dlspeed' => 1_500_000, 'upspeed' => 0],
            ],
        ]);
    }

    public function testLogsInThenReadsMainData(): void
    {
        $client = $this->client(static fn (string $url, array $options) => match (true) {
            str_ends_with($url, '/api/v2/auth/login') => self::loginOk(),
            str_contains($url, '/api/v2/sync/maindata') => self::maindata(),
        });

        $snapshot = $client->snapshot(self::BASE);

        self::assertSame(5_000_000_000_000, $snapshot->downloaded);
        self::assertSame(12_000_000_000_000, $snapshot->uploaded);
        self::assertSame(1_500_000, $snapshot->downloadSpeed);
        self::assertSame(250_000, $snapshot->uploadSpeed);
        self::assertSame(2.4, $snapshot->ratio);
        self::assertEquals(new QbittorrentTorrent('h2', 'film.mkv', 4_000_000_000, 0.42, 'downloading', 1792400000, null), $snapshot->torrents[1]);
        self::assertSame(1792300600, $snapshot->torrents[0]->completedOn);

        [$method, $url, $options] = $this->requests[0];
        self::assertSame(['POST', self::BASE.'/api/v2/auth/login'], [$method, $url]);
        self::assertSame('username=admin&password=secret', $options['body']);

        [, , $options] = $this->requests[1];
        self::assertContains('Cookie: QBT_SID_8080=sid-1', $options['headers'], 'le cookie de session est renvoyé tel quel');
    }

    public function testLoginAnsweredWith204(): void
    {
        // qBittorrent 5.2 : connexion réussie = 204 sans corps (« Ok. » avant).
        $client = $this->client(static fn (string $url) => str_ends_with($url, '/login')
            ? new MockResponse('', ['http_code' => 204, 'response_headers' => ['Set-Cookie' => 'QBT_SID_8080=sid-5; HttpOnly']])
            : self::maindata());

        self::assertSame(2.4, $client->snapshot(self::BASE)->ratio);
        self::assertContains('Cookie: QBT_SID_8080=sid-5', $this->requests[1][2]['headers']);
    }

    public function testSessionIsReused(): void
    {
        $client = $this->client(static fn (string $url) => str_ends_with($url, '/login') ? self::loginOk() : self::maindata());

        $client->snapshot(self::BASE);
        $client->snapshot(self::BASE);

        self::assertCount(3, $this->requests, 'une seule connexion pour deux lectures');
    }

    public function testExpiredSessionLogsInAgainOnce(): void
    {
        $cookies = ['SID=ancien', 'SID=nouveau'];
        $client = $this->client(static function (string $url, array $options) use (&$cookies) {
            if (str_ends_with($url, '/login')) {
                return self::loginOk(array_shift($cookies));
            }

            return \in_array('Cookie: SID=ancien', $options['headers'], true) ? new MockResponse('Forbidden', ['http_code' => 403]) : self::maindata();
        });

        self::assertSame(2.4, $client->snapshot(self::BASE)->ratio);
        self::assertCount(4, $this->requests);
    }

    public function testRefusedLoginIsNotRetriedForTenMinutes(): void
    {
        $client = $this->client(static fn () => new MockResponse('Fails.', ['http_code' => 200]), 'faux');

        foreach ([1, 2] as $attempt) {
            try {
                $client->snapshot(self::BASE);
                self::fail('exception attendue');
            } catch (SourceUnavailableException $e) {
                self::assertStringContainsString('Connexion à qBittorrent refusée', $e->getMessage());
                self::assertStringContainsString('10 min', $e->getMessage());
            }
        }

        self::assertCount(1, $this->requests, 'pas de 2e essai : qBittorrent bannit après 5 échecs');
    }

    public function testRefusedLoginWithHttp401(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Connexion à qBittorrent refusée/');

        $this->client(static fn () => new MockResponse('Unauthorized', ['http_code' => 401]))->snapshot(self::BASE);
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/qBittorrent injoignable/');

        $this->client(static fn () => new MockResponse('', ['error' => 'Connection refused']))->snapshot(self::BASE);
    }

    public function testMissingCredentials(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Identifiants qBittorrent absents (QBITTORRENT_USERNAME, QBITTORRENT_PASSWORD)');

        $this->client(static fn () => throw new \LogicException('aucun appel attendu'), '')->snapshot(self::BASE);
    }
}
