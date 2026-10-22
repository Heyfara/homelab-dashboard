<?php

namespace App\Tests\Card\Type;

use App\Card\Type\QbittorrentCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Qbittorrent\QbittorrentClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QbittorrentCardTest extends TestCase
{
    private Dashboard $dashboard;

    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'qbittorrent' => new ServiceDefinition('qbittorrent', 'qBittorrent', url: 'https://torrents.example', check: 'http://10.0.0.4:8080', icon: 'sh-qbittorrent'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    /**
     * @param array<string, array<string, mixed>> $torrents
     */
    private function card(array $torrents = []): QbittorrentCard
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($torrents) {
            $this->urls[] = $url;

            return str_ends_with($url, '/login')
                ? new MockResponse('Ok.', ['response_headers' => ['Set-Cookie' => 'SID=s']])
                : new JsonMockResponse([
                    'server_state' => ['alltime_dl' => 5_000, 'alltime_ul' => 12_000, 'dl_info_speed' => 300, 'up_info_speed' => 20, 'global_ratio' => '2.40'],
                    'torrents' => $torrents,
                ]);
        });

        return new QbittorrentCard(new QbittorrentClient($http, new ArrayAdapter(), 'admin', 'secret'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function torrent(string $name, string $state, int $added, int $completed = -1, float $progress = 1): array
    {
        return ['name' => $name, 'size' => 1000, 'progress' => $progress, 'state' => $state, 'added_on' => $added, 'completion_on' => $completed, 'dlspeed' => 0, 'upspeed' => 0];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(QbittorrentCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('c', 'qbittorrent', null, 2, 30, $resolver->resolve(['service' => 'qbittorrent', ...$options]), 2);
    }

    public function testOptionsDefaults(): void
    {
        $card = $this->card();

        self::assertEquals(['service' => 'qbittorrent', 'limit' => 5, 'sort' => 'added'], $this->instance($card)->options);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'service sans adresse' => [['service' => 'sans-url']];
        yield 'limite' => [['limit' => 0]];
        yield 'tri inconnu' => [['sort' => 'size']];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptions(array $options): void
    {
        $this->expectException(ExceptionInterface::class);

        $this->instance($this->card(), $options);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(
            ['title' => 'Téléchargements', 'service' => 'qBittorrent', 'url' => 'https://torrents.example', 'icon' => 'sh-qbittorrent'],
            $card->describe($this->instance($card), $this->dashboard),
        );
    }

    public function testTotalsAndSpeeds(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card), $this->dashboard);

        self::assertSame(['downloaded' => 5_000, 'uploaded' => 12_000, 'ratio' => 2.4, 'downloadSpeed' => 300, 'uploadSpeed' => 20], $data['totals']);
        foreach ($this->urls as $url) {
            self::assertStringStartsWith('http://10.0.0.4:8080/', $url, 'API appelée sur check');
        }
    }

    public function testLatestAddedFirst(): void
    {
        $card = $this->card([
            'a' => self::torrent('ancien', 'stalledUP', 100, 150),
            'b' => self::torrent('récent', 'downloading', 300, -1, 0.42),
            'c' => self::torrent('milieu', 'stoppedUP', 200, 900),
        ]);
        $data = $card->fetch($this->instance($card, ['limit' => 2]), $this->dashboard);

        self::assertSame(['récent', 'milieu'], array_column($data['torrents'], 'name'));
        self::assertSame([
            'name' => 'récent',
            'size' => 1000,
            'progress' => 0.42,
            'status' => 'downloading',
            'addedAt' => '1970-01-01T00:05:00+00:00',
            'completedAt' => null,
        ], $data['torrents'][0]);
    }

    public function testLatestCompletedFirstSkipsUnfinished(): void
    {
        $card = $this->card([
            'a' => self::torrent('ancien', 'stalledUP', 100, 150),
            'b' => self::torrent('en cours', 'downloading', 300, -1, 0.42),
            'c' => self::torrent('fini en dernier', 'stoppedUP', 200, 900),
        ]);
        $data = $card->fetch($this->instance($card, ['sort' => 'completed']), $this->dashboard);

        self::assertSame(['fini en dernier', 'ancien'], array_column($data['torrents'], 'name'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function states(): iterable
    {
        yield ['downloading', 'downloading'];
        yield ['forcedDL', 'downloading'];
        yield ['metaDL', 'downloading'];
        yield ['stalledDL', 'stalled'];
        yield ['queuedDL', 'queued'];
        yield ['queuedUP', 'queued'];
        yield ['uploading', 'seeding'];
        yield ['stalledUP', 'seeding'];
        yield ['forcedUP', 'seeding'];
        yield ['stoppedDL', 'paused'];
        yield ['pausedDL', 'paused'];
        yield ['stoppedUP', 'done'];
        yield ['pausedUP', 'done'];
        yield ['checkingDL', 'checking'];
        yield ['moving', 'checking'];
        yield ['error', 'error'];
        yield ['missingFiles', 'error'];
        yield ['nouveauEtat', 'unknown'];
    }

    #[DataProvider('states')]
    public function testStatusMapping(string $state, string $status): void
    {
        $card = $this->card(['a' => self::torrent('x', $state, 100)]);

        self::assertSame($status, $card->fetch($this->instance($card), $this->dashboard)['torrents'][0]['status']);
    }
}
