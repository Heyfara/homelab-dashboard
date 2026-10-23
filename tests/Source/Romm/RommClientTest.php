<?php

namespace App\Tests\Source\Romm;

use App\Source\Romm\RommClient;
use App\Source\Romm\RommGame;
use App\Source\Romm\RommPlatform;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RommClientTest extends TestCase
{
    private const BASE = 'http://romm:8080';

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    private function client(?callable $respond = null, string $token = 'rmm_jeton'): RommClient
    {
        $respond ??= self::homelab(...);

        return new RommClient(new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$url, $options];

            return $respond((string) parse_url($url, \PHP_URL_PATH));
        }), $token);
    }

    /** Réponses d'un RomM fictif, réutilisées par les tests de la carte. */
    public static function homelab(string $path): MockResponse
    {
        $rom = static fn (int $id, ?string $name, string $file, int $platformId, string $platform, ?string $cover, string $created) => [
            'id' => $id, 'name' => $name, 'fs_name' => $file.'.zip', 'fs_name_no_ext' => $file,
            'platform_id' => $platformId, 'platform_display_name' => $platform,
            'path_cover_small' => $cover, 'created_at' => $created,
        ];
        $platform = static fn (int $id, string $slug, string $name, ?string $custom, int $count) => [
            'id' => $id, 'slug' => $slug, 'name' => $name, 'custom_name' => $custom, 'display_name' => $custom ?? $name, 'rom_count' => $count,
        ];

        return match ($path) {
            '/api/stats' => new JsonMockResponse(['PLATFORMS' => 3, 'ROMS' => 28, 'SAVES' => 7, 'STATES' => 3, 'SCREENSHOTS' => 10, 'TOTAL_FILESIZE_BYTES' => 15041308]),
            '/api/platforms' => new JsonMockResponse([
                $platform(1, 'acpc', 'Amstrad CPC', null, 21),
                $platform(4, 'amstrad-gx4000', 'Amstrad GX4000', 'GX4000', 2),
                $platform(3, 'nes', 'Nintendo Entertainment System', null, 5),
                $platform(9, 'vide', 'Plateforme vide', null, 0),
            ]),
            '/api/roms' => new JsonMockResponse(['items' => [
                $rom(29, 'Bob le Dragon', 'Bob le Dragon (Europe)', 1, 'Amstrad CPC', '/assets/romm/resources/roms/1/29/cover/small.png?ts=2026-10-23', '2026-10-23T12:20:46'),
                $rom(24, null, 'Jeu Inconnu (France)', 4, 'GX4000', null, '2026-10-20T08:00:00+00:00'),
                $rom(12, 'La Quête', 'La Quete', 3, 'Nintendo Entertainment System', 'roms/3/12/cover/small.png', '2026-10-01T10:00:00'),
            ], 'total' => null, 'limit' => 3, 'offset' => 0]),
            default => throw new \LogicException('Requête non prévue : '.$path),
        };
    }

    public function testSavesAndStatesCounts(): void
    {
        self::assertSame(['saves' => 7, 'states' => 3], $this->client()->stats(self::BASE));
        self::assertContains('Authorization: Bearer rmm_jeton', $this->requests[0][1]['headers']);
    }

    public function testPlatformsWithTheirDisplayName(): void
    {
        $platforms = $this->client()->platforms(self::BASE);

        self::assertEquals(new RommPlatform(1, 'acpc', 'Amstrad CPC', 21), $platforms[0]);
        self::assertSame('GX4000', $platforms[1]->name, 'le nom personnalisé l\'emporte');
        self::assertCount(4, $platforms);
    }

    public function testRecentGamesNewestFirstWithoutTheHeavyIndexes(): void
    {
        $games = $this->client()->recent(self::BASE, 3, [1, 4]);

        self::assertEquals(new RommGame(29, 'Bob le Dragon', 1, 'Amstrad CPC', '/assets/romm/resources/roms/1/29/cover/small.png?ts=2026-10-23', new \DateTimeImmutable('2026-10-23T12:20:46+00:00')), $games[0]);
        self::assertSame('Jeu Inconnu (France)', $games[1]->name, 'jeu non identifié : nom du fichier');
        self::assertNull($games[1]->cover);
        self::assertSame('/assets/romm/resources/roms/3/12/cover/small.png', $games[2]->cover, 'chemin relatif : préfixe des ressources ajouté');

        [$url] = $this->requests[0];
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame(['created_at', 'desc', '3'], [$query['order_by'], $query['order_dir'], $query['limit']]);
        self::assertSame(['false', 'false', 'false', 'false'], [$query['with_char_index'], $query['with_filter_values'], $query['with_rom_id_index'], $query['with_total']]);
        self::assertStringContainsString('platform_ids=1&platform_ids=4', $url, 'paramètre répété, pas platform_ids[0]=');
    }

    public function testMissingToken(): void
    {
        $this->expectExceptionObject(new SourceUnavailableException('Jeton RomM absent (ROMM_API_TOKEN)'));
        $this->client(token: '')->stats(self::BASE);
    }

    public function testRefusedToken(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Jeton RomM refusé \(401\).*roms\.read/');
        $this->client(static fn () => new MockResponse('{"detail":"Unauthorized"}', ['http_code' => 401]))->platforms(self::BASE);
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/^RomM injoignable/');
        $this->client(static fn () => new MockResponse('', ['error' => 'Connexion refusée']))->stats(self::BASE);
    }
}
