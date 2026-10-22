<?php

namespace App\Tests\Card\Type;

use App\Card\Type\MusicAssistantCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\MusicAssistant\MusicAssistantClient;
use App\Tests\Source\MusicAssistant\MusicAssistantClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MusicAssistantCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'musique' => new ServiceDefinition('musique', 'Musique', url: 'https://musique.example', check: 'http://music:8095', icon: 'sh-music-assistant'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    /** « Maintenant » : 20 s après la dernière position connue des lecteurs fictifs. */
    private function card(): MusicAssistantCard
    {
        return new MusicAssistantCard(new MusicAssistantClient(new MockHttpClient(static fn (string $m, string $u, array $o) => MusicAssistantClientTest::homelab(json_decode($o['body'], true))), 'jeton'), 1792440020.0);
    }

    private function instance(MusicAssistantCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('m', 'music-assistant', null, 2, 300, $resolver->resolve(['service' => 'musique', ...$options]), 2);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'musique', 'limit' => 12, 'speakers' => [], 'speakers_refresh' => 10], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['speakers_refresh' => 1]);
    }

    public function testDescribeAndFeeds(): void
    {
        $card = $this->card();
        $instance = $this->instance($card);

        self::assertSame(['title' => 'Musique', 'service' => 'Musique', 'url' => 'https://musique.example', 'icon' => 'sh-music-assistant'], $card->describe($instance, $this->dashboard));
        self::assertSame(['speakers' => 10], $card->feeds($instance));
    }

    public function testRecentlyPlayedWithPublicImages(): void
    {
        $card = $this->card();
        $recent = $card->fetch($this->instance($card), $this->dashboard)['recent'];

        self::assertSame(['name' => 'Titre récent', 'type' => 'track', 'image' => 'https://musique.example/imageproxy/rrr111?size=256&fmt=jpg'], $recent[0]);
        self::assertNull($recent[2]['image']);
    }

    public function testSpeakersFeedWithPositionAtResponseTime(): void
    {
        $card = $this->card();
        $speakers = $card->fetchFeed('speakers', $this->instance($card), $this->dashboard)['speakers'];

        self::assertSame([
            'id' => 'p1',
            'name' => 'Salon',
            'state' => 'playing',
            'title' => 'Chanson fictive',
            'artist' => 'Groupe fictif',
            'album' => 'Album fictif',
            'image' => 'https://musique.example/imageproxy/aaa111?size=512&fmt=jpg',
            'duration' => 200,
            'elapsed' => 70,
            'accent' => '#c14822',
        ], $speakers[0]);
        self::assertSame(30, $speakers[1]['elapsed'], 'en pause : la position ne bouge pas');
        self::assertSame(['id' => 'p3', 'name' => 'Cuisine', 'state' => 'idle'], $speakers[2]);
    }

    public function testOnlyChosenSpeakers(): void
    {
        $card = $this->card();
        $speakers = $card->fetchFeed('speakers', $this->instance($card, ['speakers' => ['Cuisine']]), $this->dashboard)['speakers'];

        self::assertSame(['Cuisine'], array_column($speakers, 'name'));
    }
}
