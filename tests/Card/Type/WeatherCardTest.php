<?php

namespace App\Tests\Card\Type;

use App\Card\Type\WeatherCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use App\Source\OpenMeteo\OpenMeteoClient;
use App\Tests\Source\OpenMeteo\OpenMeteoClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class WeatherCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
    }

    private function card(): WeatherCard
    {
        return new WeatherCard(new OpenMeteoClient(new MockHttpClient(OpenMeteoClientTest::homelab())));
    }

    private function instance(WeatherCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('w', 'weather', null, 2, 1800, $resolver->resolve(['latitude' => 48.85, 'longitude' => 2.35, ...$options]), 2);
    }

    public function testOptions(): void
    {
        self::assertEquals([
            'latitude' => 48.85, 'longitude' => 2.35, 'place' => null, 'days' => 5, 'hours' => 12, 'timezone' => 'Europe/Paris', 'api' => 'https://api.open-meteo.com',
        ], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['days' => 20]);
    }

    public function testIntegerCoordinatesAccepted(): void
    {
        self::assertSame(48, $this->instance($this->card(), ['latitude' => 48])->options['latitude']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Météo', 'place' => null], $card->describe($this->instance($card), $this->dashboard));
        self::assertSame(['title' => 'Paris', 'place' => 'Paris'], $card->describe($this->instance($card, ['place' => 'Paris']), $this->dashboard));
    }

    public function testFetch(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card), $this->dashboard);

        self::assertSame(['time' => '19:00', 'temperature' => 13, 'feelsLike' => 11, 'code' => 3, 'isDay' => false, 'wind' => 10], $data['now']);
        self::assertSame(['time' => '20:00', 'temperature' => 12, 'code' => 3, 'isDay' => false, 'rainChance' => 0], $data['hours'][0], 'l\'heure en cours est déjà dans « now »');
        self::assertCount(3, $data['hours']);
        self::assertSame(['date' => '2026-10-21', 'code' => 80, 'min' => 8, 'max' => 15, 'rainChance' => 83], $data['days'][1]);
        self::assertSame(['sunrise' => '08:18', 'sunset' => '18:51'], $data['sun']);
    }
}
