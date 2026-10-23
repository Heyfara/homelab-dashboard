<?php

namespace App\Tests\Card\Type;

use App\Card\Type\RommCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Romm\RommClient;
use App\Tests\Source\Romm\RommClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RommCardTest extends TestCase
{
    private Dashboard $dashboard;
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'jeux' => new ServiceDefinition('jeux', 'Jeux rétro', url: 'https://jeux.example/', check: 'http://romm:8080', icon: 'sh-romm'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    private function card(): RommCard
    {
        return new RommCard(new RommClient(new MockHttpClient(function (string $method, string $url) {
            $this->urls[] = $url;

            return RommClientTest::homelab((string) parse_url($url, \PHP_URL_PATH));
        }), 'rmm_jeton'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(RommCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('r', 'romm', null, 2, 900, $resolver->resolve(['service' => 'jeux', ...$options]), 2);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'jeux', 'limit' => 12, 'platforms' => []], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testLimitIsBounded(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['limit' => 0]);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Jeux rétro', 'service' => 'Jeux rétro', 'url' => 'https://jeux.example/', 'icon' => 'sh-romm'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testRecentGamesAndLibrarySummary(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card), $this->dashboard);

        self::assertSame(['games' => 28, 'platforms' => 3, 'saves' => 10], $data['stats'], 'plateformes vides non comptées, sauvegardes + états');
        self::assertSame([
            'id' => 29,
            'title' => 'Bob le Dragon',
            'platform' => 'Amstrad CPC',
            'cover' => 'https://jeux.example/assets/romm/resources/roms/1/29/cover/small.png?ts=2026-10-23',
            'link' => 'https://jeux.example/rom/29',
            'addedAt' => '2026-10-23T12:20:46+00:00',
        ], $data['games'][0]);
        self::assertNull($data['games'][1]['cover']);
        self::assertSame([
            ['name' => 'Amstrad CPC', 'games' => 21, 'link' => 'https://jeux.example/platform/1'],
            ['name' => 'Nintendo Entertainment System', 'games' => 5, 'link' => 'https://jeux.example/platform/3'],
            ['name' => 'GX4000', 'games' => 2, 'link' => 'https://jeux.example/platform/4'],
        ], $data['platforms'], 'les plus fournies d\'abord');
        self::assertStringStartsWith('http://romm:8080/api/', $this->urls[0], 'API sur l\'adresse check');
        self::assertStringContainsString('limit=12', implode(' ', $this->urls));
    }

    public function testPlatformsFilter(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card, ['platforms' => ['acpc', 'amstrad-gx4000']]), $this->dashboard);

        self::assertSame(['games' => 23, 'platforms' => 2, 'saves' => 10], $data['stats']);
        self::assertSame(['Amstrad CPC', 'GX4000'], array_column($data['platforms'], 'name'));
        $roms = array_values(array_filter($this->urls, static fn (string $u): bool => str_contains($u, '/api/roms')));
        self::assertStringContainsString('platform_ids=1&platform_ids=4', $roms[0]);
    }

    public function testFilterMatchingNoPlatformShowsNoGame(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card, ['platforms' => ['inconnue']]), $this->dashboard);

        self::assertSame([], $data['games'], 'pas de repli sur toutes les plateformes');
        self::assertSame([], array_filter($this->urls, static fn (string $u): bool => str_contains($u, '/api/roms')));
    }
}
