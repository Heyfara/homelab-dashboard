<?php

namespace App\Tests\Card\Type;

use App\Card\Type\BrickTrackerCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\BrickTracker\BrickTrackerClient;
use App\Tests\Source\BrickTracker\BrickTrackerClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class BrickTrackerCardTest extends TestCase
{
    private Dashboard $dashboard;
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'lego' => new ServiceDefinition('lego', 'Lego', url: 'https://lego.example', check: 'http://bricks:3333', icon: 'sh-bricktracker'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    private function card(): BrickTrackerCard
    {
        return new BrickTrackerCard(new BrickTrackerClient(new MockHttpClient(function (string $method, string $url) {
            $this->urls[] = $url;

            return BrickTrackerClientTest::homelab((string) parse_url($url, \PHP_URL_PATH));
        })));
    }

    private function instance(BrickTrackerCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('k', 'bricktracker', null, 2, 3600, $resolver->resolve(['service' => 'lego', ...$options]), 2);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'lego'], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Lego', 'service' => 'Lego', 'url' => 'https://lego.example', 'icon' => 'sh-bricktracker'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testStatsAndLatestSetsWithPublicLinks(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card), $this->dashboard);

        self::assertSame(['sets' => 12, 'parts' => 4321, 'minifigures' => 9, 'missingParts' => 3, 'damagedParts' => 1], $data['stats']);
        self::assertSame([
            'id' => 'aaaa1111-0000-4000-8000-000000000001',
            'number' => '99901-1',
            'name' => 'Château fictif',
            'theme' => 'Castle',
            'parts' => 1234,
            'minifigures' => 4,
            'image' => 'https://lego.example/data/sets/99901-1.jpg',
            'link' => 'https://lego.example/sets/aaaa1111-0000-4000-8000-000000000001/details',
        ], $data['sets'][0]);
        self::assertStringStartsWith('http://bricks:3333/', $this->urls[0], 'pages lues par l\'adresse de check');
    }
}
