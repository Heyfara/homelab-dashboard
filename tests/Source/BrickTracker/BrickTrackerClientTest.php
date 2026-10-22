<?php

namespace App\Tests\Source\BrickTracker;

use App\Source\BrickTracker\BrickSet;
use App\Source\BrickTracker\BrickStats;
use App\Source\BrickTracker\BrickTrackerClient;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BrickTrackerClientTest extends TestCase
{
    private const BASE = 'http://bricks:3333';

    private function client(?callable $respond = null): BrickTrackerClient
    {
        $respond ??= static fn (string $method, string $url) => self::homelab((string) parse_url($url, \PHP_URL_PATH));

        return new BrickTrackerClient(new MockHttpClient($respond));
    }

    /** Pages d'un BrickTracker fictif, réutilisées par FakeHomelab. */
    public static function homelab(string $path): MockResponse
    {
        $page = static fn (string $file) => new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/'.$file), ['response_headers' => ['Content-Type' => 'text/html; charset=utf-8']]);

        return match ($path) {
            '/' => $page('bricktracker-index.html'),
            '/statistics/' => $page('bricktracker-statistics.html'),
            default => throw new \LogicException('Requête non prévue : '.$path),
        };
    }

    public function testLatestSets(): void
    {
        $sets = $this->client()->latestSets(self::BASE);

        self::assertEquals([
            new BrickSet('aaaa1111-0000-4000-8000-000000000001', '99901-1', 'Château fictif', 'Castle', 1234, 4, '/data/sets/99901-1.jpg'),
            new BrickSet('bbbb2222-0000-4000-8000-000000000002', '99902-1', 'Fusée & base', null, 87, 0, '/data/sets/99902-1.jpg'),
        ], $sets);
    }

    public function testStatistics(): void
    {
        self::assertEquals(new BrickStats(12, 4321, 9, 3, 1), $this->client()->statistics(self::BASE));
    }

    public function testLayoutChangedIsExplained(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('« Total Sets » introuvable');

        $this->client(static fn () => new MockResponse('<html><body>Nouvelle mise en page</body></html>'))->statistics(self::BASE);
    }

    public function testLoginRequired(): void
    {
        $this->expectExceptionMessage('demande une connexion');

        $this->client(static fn () => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => '/login?next=%2Fstatistics%2F']]))->statistics(self::BASE);
    }

    public function testUnreachable(): void
    {
        $this->expectExceptionMessage('BrickTracker injoignable');

        $this->client(static fn () => new MockResponse('', ['error' => 'Connexion refusée']))->latestSets(self::BASE);
    }
}
