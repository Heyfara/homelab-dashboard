<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'API telle que le front l'appelle, sur tests/fixtures/dashboard.yaml.
 */
final class ApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @return array<mixed>
     */
    private function getJson(string $url, int $expectedStatus = 200): array
    {
        $this->client->request('GET', $url);
        $response = $this->client->getResponse();

        self::assertSame($expectedStatus, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testProfiles(): void
    {
        self::assertSame([
            ['slug' => 'alice', 'name' => 'Alice', 'color' => 'indigo'],
            ['slug' => 'bob', 'name' => 'Bob', 'color' => 'slate'],
        ], $this->getJson('/api/profiles'));
    }

    public function testDashboardLayout(): void
    {
        $json = $this->getJson('/api/dashboards/alice');

        self::assertSame(['slug' => 'alice', 'name' => 'Alice', 'color' => 'indigo'], $json['profile']);
        self::assertSame(['columns' => 3, 'refresh' => 20], $json['settings']);
        self::assertCount(2, $json['sections']);
        self::assertSame('Services', $json['sections'][0]['title']);
        self::assertSame(3, $json['sections'][0]['columns']);

        $card = $json['sections'][0]['cards'][0];
        self::assertMatchesRegularExpression('/^alice-service-[0-9a-f]{6}$/', $card['id']);
        self::assertSame([
            'id' => $card['id'],
            'type' => 'service',
            'title' => null,
            'span' => 1,
            'rows' => 1,
            'refresh' => 20,
            'feeds' => [],
            'props' => [
                'name' => 'Photos',
                'description' => 'Galerie de la famille',
                'url' => 'https://photos.example',
                'icon' => 'sh-immich',
                'host' => 'alpha',
            ],
        ], $json['sections'][0]['cards'][0]);
    }

    public function testUnknownProfile(): void
    {
        $json = $this->getJson('/api/dashboards/carole', 404);

        self::assertStringContainsString('carole', $json['error']);
    }

    public function testCardData(): void
    {
        $id = $this->getJson('/api/dashboards/alice')['sections'][0]['cards'][0]['id'];
        $json = $this->getJson('/api/cards/'.$id);

        self::assertSame('ok', $json['status']);
        self::assertSame('running', $json['data']['container']['status']);
        self::assertTrue($json['data']['probe']['up']);
    }

    public function testCardDataForServiceDown(): void
    {
        $json = $this->getJson('/api/cards/recettes-alice');

        self::assertSame('ok', $json['status'], 'un service en panne est une donnée, pas une erreur de carte');
        self::assertFalse($json['data']['probe']['up']);
        self::assertSame(503, $json['data']['probe']['statusCode']);
    }

    public function testJellyfinLatestCard(): void
    {
        $layout = $this->getJson('/api/dashboards/bob');
        $card = $layout['sections'][0]['cards'][1];
        self::assertSame(['jellyfin-latest', 2, 2], [$card['type'], $card['span'], $card['rows']]);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame('Film fictif', $json['data']['items'][0]['title']);
        self::assertSame(['sessions' => 10], $card['feeds']);

        $sessions = $this->getJson('/api/cards/'.$card['id'].'?feed=sessions');
        self::assertSame('ok', $sessions['status'], (string) $sessions['error']);
        self::assertSame('Bluey', $sessions['data']['sessions'][0]['title']);

        $this->getJson('/api/cards/'.$card['id'].'?feed=inexistant', 404);
        self::assertStringStartsWith('https://films.example/Items/m1/Images/Primary', $json['data']['items'][0]['image']);
    }

    public function testQbittorrentCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][2];
        self::assertSame('qbittorrent', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame(3000, $json['data']['totals']['uploaded']);
        self::assertSame('seeding', $json['data']['torrents'][0]['status']);
    }

    public function testC411Card(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][3];
        self::assertSame('c411', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame(37, $json['data']['level']['level']);
    }

    public function testMachineCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][4];
        self::assertSame('machine', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame('10.0.0.2', $json['data']['machine']['ip']);
        self::assertSame('RAID', $json['data']['disks'][1]['name']);
        self::assertCount(2, $json['data']['series']['cpu']);
    }

    public function testGithubPullRequestsCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][8];
        self::assertSame('github-prs', $card['type']);
        self::assertStringStartsWith('https://github.com/search?', $card['props']['url']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame(3, $json['data']['total']);
    }

    public function testContainersCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][5];
        self::assertSame('containers', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame('photos_server', $json['data']['containers'][0]['name']);
        self::assertSame('1 j', $json['data']['containers'][0]['uptime']);
    }

    public function testBackblazeCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][6];
        self::assertSame('backblaze', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame('sauvegardes', $json['data']['buckets'][0]['name']);
        self::assertEquals(0, $json['data']['cost']['monthly'], '5 Go : sous les 10 Go offerts');
    }

    public function testMealieCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][7];
        self::assertSame('mealie', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame(3, $json['data']['total']);
        self::assertStringStartsWith('https://cuisine.example/g/home/r/', $json['data']['idea']['link']);
    }

    public function testAudiobookshelfCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][9];
        self::assertSame('audiobookshelf', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame(4, $json['data']['total']);
        self::assertSame('https://livres-audio.example/api/items/b3/cover', $json['data']['listening'][0]['cover']);
    }

    public function testImmichCardWithRelayedThumbnails(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][10];
        self::assertSame('immich', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);
        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame('https://galerie.example/memories/mem-2023', $json['data']['memories'][0]['link']);

        $this->client->request('GET', $json['data']['recent'][0]['thumb']);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertSame('octets', $response->getContent());

        $this->getJson(str_replace('thumbnail', 'preview', $json['data']['recent'][0]['thumb']), 403);
    }

    public function testCalibreWebCardWithRelayedCovers(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][11];
        self::assertSame('calibre-web', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);
        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame('https://liseuse.example/book/12', $json['data']['books'][0]['link']);

        $this->client->request('GET', $json['data']['books'][0]['cover']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testBackupsCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][12];
        self::assertSame('backups', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame(4, $json['data']['total']);
        self::assertSame('notes', $json['data']['entries'][0]['name'], 'problèmes en tête');
        self::assertSame('Cuisine', $json['data']['entries'][2]['label']);
    }

    public function testBrickTrackerCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][13];
        self::assertSame('bricktracker', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame(12, $json['data']['stats']['sets']);
        self::assertSame('https://lego.example/data/sets/99901-1.jpg', $json['data']['sets'][0]['image']);
    }

    public function testWeatherCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][14];
        self::assertSame('weather', $card['type']);
        self::assertSame('Village fictif', $card['props']['title']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame(13, $json['data']['now']['temperature']);
    }

    public function testMusicAssistantCardAndSpeakersFeed(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][15];
        self::assertSame('music-assistant', $card['type']);
        self::assertEquals(['speakers' => 10], $card['feeds']);

        $json = $this->getJson('/api/cards/'.$card['id']);
        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame('https://musique.example/imageproxy/rrr111?size=256&fmt=jpg', $json['data']['recent'][0]['image']);

        $feed = $this->getJson('/api/cards/'.$card['id'].'?feed=speakers');
        self::assertSame('ok', $feed['status'], (string) $feed['error']);
        self::assertSame(['Salon', 'Chambre', 'Cuisine', 'Web (Firefox)'], array_column($feed['data']['speakers'], 'name'));
    }

    public function testJeluCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][16];
        self::assertSame('jelu', $card['type']);

        $this->client->request('GET', '/api/cards/'.$card['id']);
        $raw = (string) $this->client->getResponse()->getContent();
        $json = json_decode($raw, true);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame('Alice', $json['data']['reading'][0]['reader']);
        self::assertStringNotContainsString('HASH', $raw, 'le mot de passe du DTO utilisateur Jelu ne sort pas');
        self::assertStringNotContainsString('note privée', $raw);
    }

    public function testCraftyCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][17];
        self::assertSame('crafty', $card['type']);

        $this->client->request('GET', '/api/cards/'.$card['id']);
        $raw = (string) $this->client->getResponse()->getContent();
        $json = json_decode($raw, true);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame(['Steve', 'Alex'], $json['data']['servers'][0]['players']);
        self::assertTrue($json['data']['servers'][1]['public']);
        self::assertStringNotContainsString('SECRET', $raw, 'commande de lancement du serveur');
    }

    public function testMinecraftCard(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][18];
        self::assertSame('minecraft', $card['type']);
        self::assertSame('Monde survie', $card['props']['title']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame('online', $json['data']['server']['state']);
        self::assertSame('2026-10-20T22:01:53+00:00', $json['data']['image']['renderedAt']);
    }

    public function testGw2Card(): void
    {
        $card = $this->getJson('/api/dashboards/bob')['sections'][0]['cards'][19];
        self::assertSame('gw2', $card['type']);

        $json = $this->getJson('/api/cards/'.$card['id']);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame('Joueur.1234', $json['data']['account']['name']);
        self::assertSame(514400, $json['data']['delivery']['coins']);
    }

    public function testUnknownCard(): void
    {
        $this->getJson('/api/cards/nope', 404);
    }

    public function testUnknownApiRouteIsJson(): void
    {
        $this->getJson('/api/nimporte-quoi', 404);
    }

    public function testInvalidConfigurationIsExplained(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dash').'.yaml';
        file_put_contents($file, "profiles:\n  alice:\n    name: Alice\n    sections:\n      - title: S\n        cards:\n          - { type: meteo }\n");
        $previous = $_SERVER['DASHBOARD_CONFIG'];
        $_SERVER['DASHBOARD_CONFIG'] = $_ENV['DASHBOARD_CONFIG'] = $file;

        try {
            $json = $this->getJson('/api/profiles', 503);
        } finally {
            $_SERVER['DASHBOARD_CONFIG'] = $_ENV['DASHBOARD_CONFIG'] = $previous;
            unlink($file);
        }

        self::assertSame('Configuration du dashboard invalide', $json['error']);
        self::assertStringContainsString('meteo', $json['details'][0]);
    }
}
