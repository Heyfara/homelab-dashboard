<?php

namespace App\Tests\Http;

use App\Tests\Source\Audiobookshelf\AudiobookshelfClientTest;
use App\Tests\Source\Backup\BackupStatusClientTest;
use App\Tests\Source\BrickTracker\BrickTrackerClientTest;
use App\Tests\Source\Crafty\CraftyClientTest;
use App\Tests\Source\Gw2\Gw2ClientTest;
use App\Tests\Source\Jelu\JeluClientTest;
use App\Tests\Source\MusicAssistant\MusicAssistantClientTest;
use App\Tests\Source\OpenMeteo\OpenMeteoClientTest;
use App\Tests\Source\CalibreWeb\CalibreWebClientTest;
use App\Tests\Source\Immich\ImmichClientTest;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Réponses HTTP du homelab fictif de tests/fixtures/dashboard.yaml, branchées
 * sur le client HTTP en environnement de test (config/packages/framework.yaml).
 * Aucun test ne sort sur le réseau.
 */
final class FakeHomelab
{
    public const BESZEL = 'http://beszel.test';

    /**
     * @param array<string, mixed> $options options de la requête (corps des POST)
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $path = parse_url($url, \PHP_URL_PATH);

        if (str_starts_with($url, self::BESZEL)) {
            return match ($path) {
                '/api/collections/users/auth-with-password' => new JsonMockResponse(['token' => 'jeton-test']),
                '/api/collections/systems/records' => new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/beszel/systems.json')),
                '/api/collections/system_stats/records' => new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/beszel/stats-alpha.json')),
                '/api/collections/containers/records' => new JsonMockResponse(['items' => [
                    ['id' => 'c1', 'name' => 'photos_server', 'status' => 'Up 1 day', 'health' => 2, 'cpu' => 3.2, 'memory' => 512, 'updated' => 1792400000000],
                ]]),
                default => throw new \LogicException(\sprintf('Requête Beszel non prévue par FakeHomelab : %s %s', $method, $url)),
            };
        }

        if (str_starts_with($url, 'http://10.0.0.3:8096/')) {
            return match ($path) {
                '/Users' => new JsonMockResponse([['Name' => 'Bob', 'Id' => 'u-bob']]),
                '/Sessions' => new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/jellyfin-sessions.json')),
                '/Items/Latest' => new JsonMockResponse([
                    ['Id' => 'm1', 'Name' => 'Film fictif', 'Type' => 'Movie', 'ProductionYear' => 2024, 'ServerId' => 'srv', 'ImageTags' => ['Primary' => 't1']],
                ]),
                default => throw new \LogicException(\sprintf('Requête Jellyfin non prévue par FakeHomelab : %s %s', $method, $url)),
            };
        }

        if (str_starts_with($url, 'http://10.0.0.4:8080/')) {
            return match ($path) {
                '/api/v2/auth/login' => new MockResponse('Ok.', ['response_headers' => ['Set-Cookie' => 'SID=test; HttpOnly']]),
                '/api/v2/sync/maindata' => new JsonMockResponse([
                    'server_state' => ['alltime_dl' => 1000, 'alltime_ul' => 3000, 'dl_info_speed' => 0, 'up_info_speed' => 10, 'global_ratio' => '3.00'],
                    'torrents' => ['h1' => ['name' => 'fichier-fictif.iso', 'size' => 100, 'progress' => 1, 'state' => 'stalledUP', 'added_on' => 1792300000, 'completion_on' => 1792300100]],
                ]),
                default => throw new \LogicException(\sprintf('Requête qBittorrent non prévue par FakeHomelab : %s %s', $method, $url)),
            };
        }

        if (str_starts_with($url, 'https://api.backblazeb2.com/b2api/v3/b2_authorize_account')) {
            return new JsonMockResponse(['accountId' => 'acc', 'authorizationToken' => 't', 'apiInfo' => ['storageApi' => ['apiUrl' => 'https://api.b2.test', 'bucketId' => 'b1', 'bucketName' => 'sauvegardes']]]);
        }
        if (str_starts_with($url, 'https://api.b2.test/b2api/v3/b2_list_buckets')) {
            return new JsonMockResponse(['buckets' => [['bucketId' => 'b1', 'bucketName' => 'sauvegardes', 'bucketType' => 'allPrivate']]]);
        }
        if (str_starts_with($url, 'https://api.b2.test/b2api/v3/b2_list_file_versions')) {
            return new JsonMockResponse(['files' => [['action' => 'upload', 'contentLength' => 5_000_000_000, 'uploadTimestamp' => 1792400000000]], 'nextFileName' => null]);
        }

        if (str_starts_with($url, 'http://10.0.0.5:9000/')) {
            return match ($path) {
                '/api/users/self' => new JsonMockResponse(['groupSlug' => 'home']),
                '/api/recipes' => new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/mealie-recipes.json')),
                '/api/households/mealplans' => new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/mealie-mealplans.json')),
                default => throw new \LogicException(\sprintf('Requête Mealie non prévue par FakeHomelab : %s %s', $method, $url)),
            };
        }

        if (str_starts_with($url, 'http://10.0.0.6:13378/')) {
            return AudiobookshelfClientTest::homelab((string) $path);
        }

        if (str_starts_with($url, 'http://10.0.0.7:2283/')) {
            return ImmichClientTest::homelab((string) $path);
        }

        if (str_starts_with($url, 'http://10.0.0.8:8083/')) {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return CalibreWebClientTest::homelab((string) $path, $query);
        }

        if ('http://10.0.0.9:3000/images/backup-status.json' === $url) {
            return BackupStatusClientTest::homelab();
        }

        if (str_starts_with($url, 'http://10.0.0.10:3333/')) {
            return BrickTrackerClientTest::homelab((string) $path);
        }

        if (str_starts_with($url, 'https://meteo.test/v1/forecast')) {
            return OpenMeteoClientTest::homelab();
        }

        // Vérification des clés de « Mes clés » : adresse officielle de l'API.
        if (str_starts_with($url, 'https://gw2.test/') || str_starts_with($url, 'https://api.guildwars2.com/')) {
            return Gw2ClientTest::homelab((string) $path);
        }

        if ('https://images.example/minecraft/famille.png' === $url) {
            return new MockResponse('', ['response_headers' => ['Last-Modified' => 'Tue, 20 Oct 2026 22:01:53 GMT', 'Content-Type' => 'image/png']]);
        }

        if (str_starts_with($url, 'http://10.0.0.13:8443/')) {
            return CraftyClientTest::homelab((string) $path);
        }

        if (str_starts_with($url, 'http://10.0.0.12:11111/')) {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return JeluClientTest::homelab((string) $path, $query);
        }

        if ('http://10.0.0.11:8095/api' === $url) {
            return MusicAssistantClientTest::homelab(json_decode((string) ($options['body'] ?? '{}'), true));
        }

        if (str_starts_with($url, 'https://api.github.test/search/issues')) {
            return new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/github-search.json'));
        }

        if ('https://c411.example/api/user/profile' === $url) {
            return new MockResponse((string) file_get_contents(__DIR__.'/../fixtures/c411-profile.json'));
        }

        return match ($url) {
            'http://10.0.0.2:2283/' => new MockResponse('', ['http_code' => 200]),
            'https://recettes.example/' => new MockResponse('', ['http_code' => 503]),
            default => throw new \LogicException(\sprintf('Requête non prévue par FakeHomelab : %s %s', $method, $url)),
        };
    }
}
