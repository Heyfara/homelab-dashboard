<?php

namespace App\Tests\Card\Type;

use App\Card\Type\CraftyCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Crafty\CraftyClient;
use App\Tests\Source\Crafty\CraftyClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CraftyCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'minecraft' => new ServiceDefinition('minecraft', 'Crafty', url: 'https://crafty.example', check: 'http://crafty:8443', icon: 'sh-crafty-controller'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    private function card(): CraftyCard
    {
        return new CraftyCard(new CraftyClient(new MockHttpClient(static fn (string $m, string $url) => CraftyClientTest::homelab((string) parse_url($url, \PHP_URL_PATH))), 'cle'));
    }

    private function instance(CraftyCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('c', 'crafty', null, 2, 60, $resolver->resolve(['service' => 'minecraft', ...$options]), 2);
    }

    private function fetch(array $options = []): array
    {
        $card = $this->card();

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'minecraft', 'servers' => [], 'public' => []], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Crafty', 'service' => 'Crafty', 'url' => 'https://crafty.example', 'icon' => 'sh-crafty-controller'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testOnlineThenCrashedThenStopped(): void
    {
        $data = $this->fetch(['public' => ['Public']]);

        self::assertSame(['Monde survie', 'Public', 'Planté', 'Ancien monde'], array_column($data['servers'], 'name'), 'un serveur planté passe avant un serveur arrêté');
        self::assertSame(2, $data['playersOnline']);
        self::assertSame([
            'id' => 's1',
            'name' => 'Monde survie',
            'state' => 'online',
            'online' => 2,
            'max' => 10,
            'players' => ['Steve', 'Alex'],
            'version' => 'Paper 26.2',
            'motd' => 'Monde de la famille',
            'worldSize' => '423.2MB',
            'startedAt' => (new \DateTimeImmutable('2026-10-20 07:30:00'))->format(\DATE_ATOM),
            'public' => false,
            'link' => 'https://crafty.example/panel/server_detail?id=s1',
        ], array_diff_key($data['servers'][0], ['icon' => true]));
        self::assertStringStartsWith('data:image/png;base64,', $data['servers'][0]['icon']);
        self::assertTrue($data['servers'][1]['public']);
    }

    public function testChosenServersInGivenOrder(): void
    {
        self::assertSame(['Ancien monde', 'Monde survie'], array_column($this->fetch(['servers' => ['Ancien monde', 'Monde survie']])['servers'], 'name'));
    }
}
