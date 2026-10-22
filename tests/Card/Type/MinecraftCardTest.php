<?php

namespace App\Tests\Card\Type;

use App\Card\Type\MinecraftCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Crafty\CraftyClient;
use App\Source\Http\RemoteFile;
use App\Source\SourceUnavailableException;
use App\Tests\Source\Crafty\CraftyClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MinecraftCardTest extends TestCase
{
    private const IMAGE = 'https://images.example/minecraft/famille.png';

    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'crafty' => new ServiceDefinition('crafty', 'Crafty', url: 'https://crafty.example', check: 'http://crafty:8443', icon: 'sh-crafty-controller'),
        ], []);
    }

    private function card(?MockResponse $image = null): MinecraftCard
    {
        $crafty = new CraftyClient(new MockHttpClient(static fn (string $m, string $url) => CraftyClientTest::homelab((string) parse_url($url, \PHP_URL_PATH))), 'cle');
        $image ??= new MockResponse('', ['response_headers' => ['Last-Modified' => 'Tue, 20 Oct 2026 22:01:53 GMT']]);

        return new MinecraftCard($crafty, new RemoteFile(new MockHttpClient($image)));
    }

    private function instance(MinecraftCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('m', 'minecraft', null, 2, 300, $resolver->resolve(['service' => 'crafty', 'server' => 'Monde survie', 'image' => self::IMAGE, ...$options]), 3);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'crafty', 'server' => 'Monde survie', 'image' => self::IMAGE], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['image' => 42]);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Monde survie', 'icon' => 'sh-crafty-controller'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testServerStateAndRenderedImage(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card), $this->dashboard);

        self::assertSame([
            'state' => 'online',
            'online' => 2,
            'max' => 10,
            'players' => ['Steve', 'Alex'],
            'version' => 'Paper 26.2',
            'link' => 'https://crafty.example/panel/server_detail?id=s1',
        ], $data['server']);
        self::assertSame(['url' => self::IMAGE.'?v='.(new \DateTimeImmutable('2026-10-20T22:01:53+00:00'))->getTimestamp(), 'renderedAt' => '2026-10-20T22:01:53+00:00'], $data['image']);
    }

    public function testImageWithoutDateStillShown(): void
    {
        $card = $this->card(new MockResponse(''));

        self::assertSame(['url' => self::IMAGE, 'renderedAt' => null], $card->fetch($this->instance($card), $this->dashboard)['image']);
    }

    public function testUnknownServerListsTheNames(): void
    {
        $card = $this->card();

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Serveur Crafty inconnu « Inconnu » (serveurs : Monde survie, Public, Ancien monde, Planté)');
        $card->fetch($this->instance($card, ['server' => 'Inconnu']), $this->dashboard);
    }
}
