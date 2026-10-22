<?php

namespace App\Tests\Source\MusicAssistant;

use App\Source\MusicAssistant\MusicAssistantClient;
use App\Source\MusicAssistant\MusicItem;
use App\Source\MusicAssistant\NowPlaying;
use App\Source\MusicAssistant\Speaker;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MusicAssistantClientTest extends TestCase
{
    private const BASE = 'http://music:8095';

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    private function client(?callable $respond = null, string $token = 'jeton'): MusicAssistantClient
    {
        $respond ??= static fn (array $body) => self::homelab($body);

        return new MusicAssistantClient(new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$url, $options];

            return $respond(json_decode($options['body'], true));
        }), $token);
    }

    /**
     * Music Assistant fictif (POST /api), réutilisé par FakeHomelab.
     *
     * @param array<string, mixed> $body
     */
    public static function homelab(array $body): MockResponse
    {
        $file = match ($body['command'] ?? null) {
            'players/all' => 'music-assistant-players.json',
            'music/recently_played_items' => 'music-assistant-recent.json',
            default => throw new \LogicException('Commande non prévue : '.json_encode($body)),
        };

        return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/'.$file), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    public function testAvailableSpeakersAndHiddenPlayersOnlyWhilePlaying(): void
    {
        $speakers = $this->client()->speakers(self::BASE);

        // « Web (…) » : lecteur du navigateur, masqué dans Music Assistant ; montré seulement quand il joue.
        self::assertSame(['Salon', 'Chambre', 'Cuisine', 'Web (Firefox)'], array_map(static fn (Speaker $s): string => $s->name, $speakers));
        self::assertEquals(new Speaker('p1', 'Salon', 'playing', new NowPlaying(
            'Chanson fictive', 'Groupe fictif', 'Album fictif', '/imageproxy/aaa111?size=512&fmt=jpg', 200, 50.0, 1792440000.0, '#c14822',
        )), $speakers[0]);
        [$url, $options] = $this->requests[0];
        self::assertSame(self::BASE.'/api', $url);
        self::assertContains('Authorization: Bearer jeton', $options['headers']);
        self::assertSame('players/all', json_decode($options['body'], true)['command']);
    }

    public function testPositionInTheTrackNotInTheQueue(): void
    {
        self::assertSame(50.0, $this->client()->speakers(self::BASE)[0]->playing?->elapsed, 'position du lecteur (350 s) cumulée sur la file');
    }

    /**
     * @return iterable<string, array{list<int>, ?string}>
     */
    public static function accents(): iterable
    {
        yield 'couleur franche' => [[193, 72, 34], '#c14822'];
        yield 'presque noire : invisible en sombre' => [[36, 40, 42], null];
        yield 'presque blanche : invisible en clair' => [[245, 245, 240], null];
    }

    /**
     * @param list<int> $rgb
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('accents')]
    public function testAccentOnlyWhenVisibleInBothThemes(array $rgb, ?string $expected): void
    {
        $players = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/music-assistant-players.json'), true);
        $players[0]['current_media']['palette']['accent'] = $rgb;

        $speakers = $this->client(static fn () => new MockResponse(json_encode($players, \JSON_THROW_ON_ERROR)))->speakers(self::BASE);

        self::assertSame($expected, $speakers[0]->playing?->accent);
    }

    public function testIdleSpeakerHasNoMedia(): void
    {
        self::assertNull($this->client()->speakers(self::BASE)[2]->playing, 'à l\'arrêt : le dernier titre ne compte pas');
    }

    public function testRecentlyPlayedWithProxiedImagesOnly(): void
    {
        $items = $this->client()->recentlyPlayed(self::BASE, 8);

        self::assertEquals([
            new MusicItem('Titre récent', 'track', '/imageproxy/rrr111?size=256&fmt=jpg'),
            new MusicItem('Artiste fictif', 'artist', '/imageproxy/rrr222?size=256&fmt=jpg'),
            new MusicItem('Sans image', 'album', null),
        ], $items);
        self::assertSame(['limit' => 8], json_decode($this->requests[0][1]['body'], true)['args']);
        self::assertStringNotContainsString('api_key', serialize($items), 'le chemin d\'origine porte la clé Jellyfin');
    }

    public function testRefusedToken(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Jeton Music Assistant refusé (401)');

        $this->client(static fn () => new MockResponse('Authentication required', ['http_code' => 401]))->speakers(self::BASE);
    }

    public function testMissingToken(): void
    {
        $this->expectExceptionMessage('MUSIC_ASSISTANT_TOKEN');

        $this->client(token: '')->speakers(self::BASE);
    }

    public function testUnreachable(): void
    {
        $this->expectExceptionMessage('Music Assistant injoignable');

        $this->client(static fn () => new MockResponse('', ['error' => 'Connexion refusée']))->speakers(self::BASE);
    }
}
