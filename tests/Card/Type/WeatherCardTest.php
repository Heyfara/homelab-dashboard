<?php

namespace App\Tests\Card\Type;

use App\Card\Type\WeatherCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use App\Profile\ProfileFiles;
use App\Profile\ProfileSettings;
use App\Profile\WeatherLocation;
use App\Source\OpenMeteo\OpenMeteoClient;
use App\Source\SourceUnavailableException;
use App\Tests\Source\OpenMeteo\OpenMeteoClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class WeatherCardTest extends TestCase
{
    private Dashboard $dashboard;
    private string $dir;
    private ProfileSettings $settings;
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
        $this->dir = sys_get_temp_dir().'/weather-card-'.bin2hex(random_bytes(4));
        $this->settings = new ProfileSettings(new ProfileFiles($this->dir));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/{,.}*.*', \GLOB_BRACE) ?: []);
        @rmdir($this->dir);
    }

    private function card(): WeatherCard
    {
        return new WeatherCard(new OpenMeteoClient(new MockHttpClient(function (string $method, string $url) {
            $this->urls[] = $url;

            return OpenMeteoClientTest::homelab();
        })), new WeatherLocation($this->settings));
    }

    private function instance(WeatherCard $card, array $options = ['latitude' => 48.85, 'longitude' => 2.35], ?string $profile = null): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('w', 'weather', null, 2, 1800, $resolver->resolve($options), 2, $profile);
    }

    public function testOptions(): void
    {
        self::assertEquals([
            'latitude' => 48.85, 'longitude' => 2.35, 'place' => null, 'location' => null, 'days' => 5, 'hours' => 12, 'timezone' => 'Europe/Paris', 'api' => 'https://api.open-meteo.com',
        ], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['latitude' => 48.85, 'longitude' => 2.35, 'days' => 20]);
    }

    public function testCoordinatesRequiredUnlessChosenByTheProfile(): void
    {
        self::assertNull($this->instance($this->card(), ['location' => 'profile'])->options['latitude'], 'location: profile sans lieu par défaut');

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['place' => 'Nulle part']);
    }

    public function testLocationOptionAcceptsOnlyProfile(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['latitude' => 48.85, 'longitude' => 2.35, 'location' => 'zoe']);
    }

    public function testPlaceChosenByTheProfileReplacesTheYaml(): void
    {
        $this->settings->set('zoe', WeatherLocation::SETTING, ['place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49]);
        $card = $this->card();
        $instance = $this->instance($card, ['place' => 'Maison', 'latitude' => 48.85, 'longitude' => 2.35, 'location' => 'profile'], 'zoe');

        self::assertSame(['title' => 'Brest', 'place' => 'Brest'], $card->describe($instance, $this->dashboard));
        $card->fetch($instance, $this->dashboard);
        parse_str((string) parse_url($this->urls[0], \PHP_URL_QUERY), $query);
        self::assertSame(['48.39', '-4.49'], [$query['latitude'], $query['longitude']]);
    }

    public function testYamlPlaceUntilTheProfileChoosesOne(): void
    {
        $card = $this->card();
        $instance = $this->instance($card, ['place' => 'Maison', 'latitude' => 48.85, 'longitude' => 2.35, 'location' => 'profile'], 'zoe');

        self::assertSame(['title' => 'Maison', 'place' => 'Maison'], $card->describe($instance, $this->dashboard));
    }

    public function testPlaceOfAnotherProfileIsIgnoredWithoutLocationProfile(): void
    {
        $this->settings->set('zoe', WeatherLocation::SETTING, ['place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49]);
        $card = $this->card();

        self::assertSame('Maison', $card->describe($this->instance($card, ['place' => 'Maison', 'latitude' => 48.85, 'longitude' => 2.35], 'zoe'), $this->dashboard)['place']);
    }

    public function testNoPlaceYetSaysWhatToDo(): void
    {
        $card = $this->card();
        $instance = $this->instance($card, ['location' => 'profile'], 'zoe');

        self::assertSame(['title' => 'Météo', 'place' => null], $card->describe($instance, $this->dashboard));
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('« Ma config »');
        $card->fetch($instance, $this->dashboard);
    }

    public function testIntegerCoordinatesAccepted(): void
    {
        self::assertSame(48, $this->instance($this->card(), ['latitude' => 48, 'longitude' => 2])->options['latitude']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Météo', 'place' => null], $card->describe($this->instance($card), $this->dashboard));
        self::assertSame(['title' => 'Paris', 'place' => 'Paris'], $card->describe($this->instance($card, ['place' => 'Paris', 'latitude' => 48.85, 'longitude' => 2.35]), $this->dashboard));
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
