<?php

namespace App\Tests\Source\Jellyfin;

use App\Source\Jellyfin\JellyfinClient;
use App\Source\Jellyfin\JellyfinItem;
use App\Source\Jellyfin\JellyfinSession;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JellyfinClientTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    private function client(callable $respond, string $apiKey = 'cle-api'): JellyfinClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$method, $url, $options];

            return $respond($url);
        });

        return new JellyfinClient($http, new ArrayAdapter(), $apiKey);
    }

    private static function users(): JsonMockResponse
    {
        return new JsonMockResponse([['Name' => 'Tom', 'Id' => 'u-tom'], ['Name' => 'Zoe', 'Id' => 'u-zoe']]);
    }

    public function testUserIdByNameIgnoringCase(): void
    {
        $client = $this->client(static fn () => self::users());

        self::assertSame('u-zoe', $client->userId('http://jellyfin:8096', 'zoe'));
        self::assertSame('u-tom', $client->userId('http://jellyfin:8096', 'Tom'));
        self::assertCount(1, $this->requests, 'liste des utilisateurs mise en cache');

        [$method, $url, $options] = $this->requests[0];
        self::assertSame('http://jellyfin:8096/Users', $url);
        self::assertContains('Authorization: MediaBrowser Token="cle-api"', $options['headers']);
    }

    public function testUnknownUser(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Utilisateur Jellyfin inconnu « lea » (utilisateurs : Tom, Zoe)');

        $this->client(static fn () => self::users())->userId('http://jellyfin:8096', 'lea');
    }

    public function testLatestItems(): void
    {
        $client = $this->client(static fn () => new JsonMockResponse([
            ['Id' => 'm1', 'Name' => 'Le Voyage de Chihiro', 'Type' => 'Movie', 'ProductionYear' => 2001, 'ServerId' => 'srv', 'ImageTags' => ['Primary' => 'tag-m1'], 'DateCreated' => '2026-10-18T20:00:00.0000000Z'],
            ['Id' => 'e1', 'Name' => 'Le Pilote', 'Type' => 'Episode', 'SeriesName' => 'Bluey', 'SeriesId' => 's1', 'SeriesPrimaryImageTag' => 'tag-s1', 'ParentIndexNumber' => 3, 'IndexNumber' => 1, 'ServerId' => 'srv', 'ImageTags' => [], 'DateCreated' => '2026-10-17T10:00:00Z'],
        ]));

        $items = $client->latest('http://jellyfin:8096', 'u-tom', 12, ['Movie', 'Episode']);

        self::assertEquals(new JellyfinItem('m1', 'Le Voyage de Chihiro', 'Movie', 'srv', 'm1', 'tag-m1', year: 2001, addedAt: new \DateTimeImmutable('2026-10-18T20:00:00Z')), $items[0]);
        self::assertEquals(new JellyfinItem('e1', 'Le Pilote', 'Episode', 'srv', 's1', 'tag-s1', seriesName: 'Bluey', season: 3, episode: 1, addedAt: new \DateTimeImmutable('2026-10-17T10:00:00Z')), $items[1]);

        [, $url] = $this->requests[0];
        self::assertStringStartsWith('http://jellyfin:8096/Items/Latest?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame('u-tom', $query['userId']);
        self::assertSame('12', $query['limit']);
        self::assertSame('Movie,Episode', $query['includeItemTypes']);
    }

    public function testSessionsThatArePlaying(): void
    {
        $client = $this->client(static fn () => new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/jellyfin-sessions.json')));

        $sessions = $client->sessions('http://jellyfin:8096');

        self::assertCount(2, $sessions, 'une session sans lecture n\'est pas une lecture en cours');
        self::assertEquals(new JellyfinSession('s1', 'Membre', 'Jellyfin Web', 'Firefox', new JellyfinItem('m1', 'Primer', 'Movie', 'srv', 'm1', 't1', year: 2004), 1159, 4634, true, true), $sessions[0]);
        self::assertSame(['Bluey', 'series1', false, false], [$sessions[1]->item->seriesName, $sessions[1]->item->imageItemId, $sessions[1]->paused, $sessions[1]->transcoding]);
        self::assertStringStartsWith('http://jellyfin:8096/Sessions?', $this->requests[0][1]);
        self::assertStringContainsString('activeWithinSeconds=960', $this->requests[0][1]);
    }

    public function testItemWithoutImage(): void
    {
        $items = $this->client(static fn () => new JsonMockResponse([
            ['Id' => 'm1', 'Name' => 'Sans affiche', 'Type' => 'Movie', 'ServerId' => 'srv', 'ImageTags' => []],
        ]))->latest('http://jellyfin:8096', 'u', 5, ['Movie']);

        self::assertNull($items[0]->imageTag);
        self::assertNull($items[0]->addedAt);
    }

    public function testRejectedKey(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/clé API Jellyfin refusée/i');

        $this->client(static fn () => new MockResponse('', ['http_code' => 401]))->userId('http://jellyfin:8096', 'tom');
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Jellyfin injoignable/');

        $this->client(static fn () => new MockResponse('', ['error' => 'Connection refused']))->latest('http://jellyfin:8096', 'u', 5, ['Movie']);
    }

    public function testMissingKey(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage("Clé API Jellyfin absente (JELLYFIN_API_KEY)");

        $this->client(static fn () => throw new \LogicException('aucun appel attendu'), '')->userId('http://jellyfin:8096', 'tom');
    }
}
