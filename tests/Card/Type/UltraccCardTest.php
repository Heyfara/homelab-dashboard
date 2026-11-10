<?php

namespace App\Tests\Card\Type;

use App\Card\Type\UltraccCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\SourceUnavailableException;
use App\Source\Ultracc\UltraccClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class UltraccCardTest extends TestCase
{
    private Dashboard $dashboard;

    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'seedbox' => new ServiceDefinition('seedbox', 'Seedbox', url: 'https://tom.serveur.usbx.example/qbittorrent', icon: 'sh-qbittorrent'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    private function card(string $now = '2026-03-20T00:00:00Z', ?MockResponse $response = null): UltraccCard
    {
        $response ??= new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/ultracc-total-stats.json'));
        $http = new MockHttpClient(function (string $method, string $url) use ($response) {
            $this->urls[] = $url;

            return $response;
        });

        return new UltraccCard(new UltraccClient($http, 'jeton'), $now);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(UltraccCard $card, array $options = ['service' => 'seedbox'], ?string $title = null): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('u', 'ultracc', $title, 1, 900, $resolver->resolve($options), 2);
    }

    public function testOptionsDefaults(): void
    {
        self::assertEquals(['service' => 'seedbox', 'traffic_quota' => null, 'icon' => null], $this->instance($this->card())->options);
    }

    public function testServiceMustHaveAnUrl(): void
    {
        $this->expectException(ExceptionInterface::class);

        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testTrafficQuotaMustBePositive(): void
    {
        $this->expectException(ExceptionInterface::class);

        $this->instance($this->card(), ['service' => 'seedbox', 'traffic_quota' => 0]);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(
            ['title' => 'Seedbox', 'url' => 'https://tom.serveur.usbx.example/qbittorrent', 'icon' => 'sh-qbittorrent'],
            $card->describe($this->instance($card), $this->dashboard),
        );
        self::assertSame('Abonnement', $card->describe($this->instance($card, title: 'Abonnement'), $this->dashboard)['title']);
        // L'icône de la carte remplace celle du service (qBittorrent sur la seedbox).
        self::assertSame('ultracc.svg', $card->describe($this->instance($card, ['service' => 'seedbox', 'icon' => 'ultracc.svg']), $this->dashboard)['icon']);
    }

    public function testFetch(): void
    {
        // 10 jours écoulés sur 31 : 24,5 % au rythme actuel → 75,95 % en fin de période.
        $card = $this->card();

        $data = $card->fetch($this->instance($card, ['service' => 'seedbox', 'traffic_quota' => 2000]), $this->dashboard);

        self::assertSame(['https://tom.serveur.usbx.example/ultra-api/total-stats'], $this->urls);
        self::assertSame(['used' => 632.0, 'total' => 932.0, 'free' => 300.0, 'percent' => 67.81], $data['storage']);
        self::assertSame([
            'percent' => 24.5,
            'used' => 490.0,
            'quota' => 2000,
            'lastReset' => '2026-03-10T00:00:00+00:00',
            'nextReset' => '2026-04-10T00:00:00+00:00',
            'projectedPercent' => 75.95,
        ], $data['traffic']);
    }

    public function testWithoutQuotaOnlyPercentages(): void
    {
        $card = $this->card();

        $traffic = $card->fetch($this->instance($card), $this->dashboard)['traffic'];

        self::assertNull($traffic['used']);
        self::assertNull($traffic['quota']);
    }

    public function testNoProjectionDuringTheFirstDay(): void
    {
        // Quelques heures après la remise à zéro, l'extrapolation n'a pas de sens.
        $card = $this->card('2026-03-10T18:00:00Z');

        self::assertNull($card->fetch($this->instance($card), $this->dashboard)['traffic']['projectedPercent']);
    }

    public function testSourceDown(): void
    {
        $card = $this->card(response: new MockResponse('', ['http_code' => 401]));

        $this->expectException(SourceUnavailableException::class);

        $card->fetch($this->instance($card), $this->dashboard);
    }
}
