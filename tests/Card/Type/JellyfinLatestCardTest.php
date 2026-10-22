<?php

namespace App\Tests\Card\Type;

use App\Card\Type\JellyfinLatestCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Jellyfin\JellyfinClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class JellyfinLatestCardTest extends TestCase
{
    private Dashboard $dashboard;

    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'jellyfin' => new ServiceDefinition('jellyfin', 'Jellyfin', url: 'https://jellyfin.example', check: 'http://jellyfin:8096', icon: 'sh-jellyfin'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    private function card(): JellyfinLatestCard
    {
        $http = new MockHttpClient(function (string $method, string $url) {
            $this->urls[] = $url;

            if (str_contains($url, '/Sessions')) {
                return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/jellyfin-sessions.json'));
            }

            return str_contains($url, '/Users')
                ? new JsonMockResponse([['Name' => 'Tom', 'Id' => 'u-tom']])
                : new JsonMockResponse([
                    ['Id' => 'm1', 'Name' => 'Le Voyage de Chihiro', 'Type' => 'Movie', 'ProductionYear' => 2001, 'ServerId' => 'srv', 'ImageTags' => ['Primary' => 'tag-m1'], 'DateCreated' => '2026-10-18T20:00:00Z'],
                    ['Id' => 'e1', 'Name' => 'Le Pilote', 'Type' => 'Episode', 'SeriesName' => 'Bluey', 'SeriesId' => 's1', 'SeriesPrimaryImageTag' => 'tag-s1', 'ParentIndexNumber' => 3, 'IndexNumber' => 1, 'ServerId' => 'srv'],
                    ['Id' => 'm2', 'Name' => 'Sans affiche', 'Type' => 'Movie', 'ServerId' => 'srv', 'ImageTags' => []],
                ]);
        });

        return new JellyfinLatestCard(new JellyfinClient($http, new ArrayAdapter(), 'cle'));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function resolve(array $options): array
    {
        $resolver = new OptionsResolver();
        $this->card()->configureOptions($resolver, $this->dashboard);

        return $resolver->resolve($options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(array $options = []): CardInstance
    {
        return new CardInstance('c', 'jellyfin-latest', null, 2, 300, $this->resolve(['service' => 'jellyfin', 'user' => 'tom', ...$options]), 2);
    }

    public function testOptionsDefaults(): void
    {
        self::assertEquals(
            ['service' => 'jellyfin', 'user' => 'tom', 'limit' => 12, 'types' => ['Movie', 'Episode'], 'now_playing' => true, 'now_playing_refresh' => 10],
            $this->resolve(['service' => 'jellyfin', 'user' => 'tom']),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'service absent du catalogue' => [['service' => 'plex', 'user' => 'tom']];
        yield 'service sans adresse' => [['service' => 'sans-url', 'user' => 'tom']];
        yield 'sans utilisateur' => [['service' => 'jellyfin']];
        yield 'limite trop grande' => [['service' => 'jellyfin', 'user' => 'tom', 'limit' => 50]];
        yield 'type inconnu' => [['service' => 'jellyfin', 'user' => 'tom', 'types' => ['Movie', 'Podcast']]];
        yield 'lectures en cours trop fréquentes' => [['service' => 'jellyfin', 'user' => 'tom', 'now_playing_refresh' => 2]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOptions')]
    public function testInvalidOptions(array $options): void
    {
        $this->expectException(ExceptionInterface::class);

        $this->resolve($options);
    }

    public function testDescribe(): void
    {
        self::assertSame(
            ['title' => 'Derniers ajouts', 'service' => 'Jellyfin', 'url' => 'https://jellyfin.example', 'icon' => 'sh-jellyfin'],
            $this->card()->describe($this->instance(), $this->dashboard),
        );
    }

    public function testFetchUsesInternalAddressAndBuildsPublicLinks(): void
    {
        $data = $this->card()->fetch($this->instance(), $this->dashboard);

        foreach ($this->urls as $url) {
            self::assertStringStartsWith('http://jellyfin:8096/', $url, 'API appelée sur l\'adresse interne (check)');
        }

        self::assertSame([
            'id' => 'm1',
            'title' => 'Le Voyage de Chihiro',
            'subtitle' => '2001',
            'image' => 'https://jellyfin.example/Items/m1/Images/Primary?fillHeight=360&quality=90&tag=tag-m1',
            'link' => 'https://jellyfin.example/web/#/details?id=m1&serverId=srv',
            'addedAt' => '2026-10-18T20:00:00+00:00',
        ], $data['items'][0]);
    }

    public function testEpisodeShowsItsSeries(): void
    {
        $episode = $this->card()->fetch($this->instance(), $this->dashboard)['items'][1];

        self::assertSame('Bluey', $episode['title']);
        self::assertSame('S03E01 · Le Pilote', $episode['subtitle']);
        self::assertSame('https://jellyfin.example/Items/s1/Images/Primary?fillHeight=360&quality=90&tag=tag-s1', $episode['image']);
        self::assertSame('https://jellyfin.example/web/#/details?id=e1&serverId=srv', $episode['link']);
    }

    public function testNowPlayingIsAFasterFeed(): void
    {
        $card = $this->card();

        self::assertSame(['sessions' => 10], $card->feeds($this->instance()));
        self::assertSame(['sessions' => 30], $card->feeds($this->instance(['now_playing_refresh' => 30])));
        self::assertSame([], $card->feeds($this->instance(['now_playing' => false])));
    }

    public function testNowPlayingSessions(): void
    {
        $sessions = $this->card()->fetchFeed('sessions', $this->instance(), $this->dashboard)['sessions'];

        self::assertSame(['Enfant', 'Membre'], array_column($sessions, 'user'), 'lectures actives avant celles en pause');
        self::assertSame([
            'id' => 's1',
            'user' => 'Membre',
            'device' => 'Firefox',
            'client' => 'Jellyfin Web',
            'title' => 'Primer',
            'subtitle' => '2004',
            'image' => 'https://jellyfin.example/Items/m1/Images/Primary?fillHeight=360&quality=90&tag=t1',
            'link' => 'https://jellyfin.example/web/#/details?id=m1&serverId=srv',
            'position' => 1159,
            'duration' => 4634,
            'paused' => true,
            'transcoding' => true,
        ], $sessions[1]);
        self::assertSame(['Bluey', 'S03E01 · Le Pilote'], [$sessions[0]['title'], $sessions[0]['subtitle']]);
    }

    public function testItemWithoutPoster(): void
    {
        self::assertNull($this->card()->fetch($this->instance(), $this->dashboard)['items'][2]['image']);
    }
}
