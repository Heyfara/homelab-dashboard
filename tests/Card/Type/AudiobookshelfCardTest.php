<?php

namespace App\Tests\Card\Type;

use App\Card\Type\AudiobookshelfCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Audiobookshelf\AudiobookshelfClient;
use App\Tests\Source\Audiobookshelf\AudiobookshelfClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AudiobookshelfCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'abs' => new ServiceDefinition('abs', 'Audiobookshelf', url: 'https://abs.example', check: 'http://abs:80', icon: 'sh-audiobookshelf'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    private function card(): AudiobookshelfCard
    {
        return new AudiobookshelfCard(new AudiobookshelfClient(new MockHttpClient(static function (string $method, string $url) {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return AudiobookshelfClientTest::homelab((string) parse_url($url, \PHP_URL_PATH), $query);
        }), 'cle'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(AudiobookshelfCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('a', 'audiobookshelf', null, 2, 300, $resolver->resolve(['service' => 'abs', ...$options]), 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(array $options = []): array
    {
        $card = $this->card();

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'abs', 'user' => null, 'limit' => 12], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Audiobookshelf', 'service' => 'Audiobookshelf', 'url' => 'https://abs.example', 'icon' => 'sh-audiobookshelf'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testListeningWithPublicCoversAndRemainingTime(): void
    {
        $listening = $this->fetch()['listening'];

        self::assertSame([
            'id' => 'b3',
            'title' => 'Livre b3',
            'author' => 'Auteur',
            'cover' => 'https://abs.example/api/items/b3/cover',
            'link' => 'https://abs.example/item/b3',
            'progress' => 0.73,
            'remaining' => 1106,
            'lastPlayed' => '2026-10-12T19:54:44+00:00',
        ], $listening[0]);
        self::assertCount(2, $listening);
    }

    public function testRecentBooks(): void
    {
        $data = $this->fetch();

        self::assertSame(4, $data['total']);
        self::assertSame(['id' => 'b1', 'title' => 'Astérix aux Jeux olympiques', 'author' => 'Dominique Pinon', 'cover' => 'https://abs.example/api/items/b1/cover', 'link' => 'https://abs.example/item/b1', 'duration' => 5984], $data['recent'][0]);
    }

    public function testAnotherUsersListening(): void
    {
        self::assertSame(['b1'], array_column($this->fetch(['user' => 'hugo'])['listening'], 'id'));
    }
}
