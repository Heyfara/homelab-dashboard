<?php

namespace App\Tests\Source\OpenMeteo;

use App\Source\OpenMeteo\Forecast;
use App\Source\OpenMeteo\ForecastDay;
use App\Source\OpenMeteo\ForecastHour;
use App\Source\OpenMeteo\OpenMeteoClient;
use App\Source\OpenMeteo\Place;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenMeteoClientTest extends TestCase
{
    private const API = 'https://meteo.test';

    /** @var list<string> */
    private array $urls = [];

    private function client(?MockResponse $response = null): OpenMeteoClient
    {
        return new OpenMeteoClient(new MockHttpClient(function (string $method, string $url) use ($response) {
            $this->urls[] = $url;

            return $response ?? self::homelab();
        }));
    }

    /** Prévisions fictives (relevé réel tronqué, Paris), réutilisées par FakeHomelab. */
    public static function homelab(): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/open-meteo.json'), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    /** Résultats fictifs de recherche de lieu (format réel du géocodage), réutilisés par FakeHomelab. */
    public static function places(): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/open-meteo-geocoding.json'), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    public function testSearch(): void
    {
        $places = $this->client(self::places())->search('https://geo.test', 'Lyon');

        self::assertEquals([
            new Place('Lyon', 'Rhône', 'France', 45.74846, 4.84671),
            new Place('Lyon', 'West Virginia', 'United States', 38.8673, -79.34171),
        ], $places, 'région = département s\'il est connu, sinon la région');
        parse_str((string) parse_url($this->urls[0], \PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://geo.test/v1/search?', $this->urls[0]);
        self::assertSame(['Lyon', '8', 'fr'], [$query['name'], $query['count'], $query['language']]);
    }

    public function testSearchWithoutResult(): void
    {
        self::assertSame([], $this->client(new MockResponse('{"generationtime_ms":0.1}'))->search('https://geo.test', 'zzzqqq'));
    }

    public function testSearchUnreachable(): void
    {
        $this->expectExceptionMessage('Recherche de lieu Open-Meteo injoignable');

        $this->client(new MockResponse('', ['error' => 'Connexion refusée']))->search('https://geo.test', 'Lyon');
    }

    public function testForecast(): void
    {
        $forecast = $this->client()->forecast(self::API, 48.85, 2.35, 3, 4);

        self::assertEquals(new Forecast(
            new ForecastHour(new \DateTimeImmutable('2026-10-20T19:00', new \DateTimeZone('Europe/Paris')), 12.7, 3, false, null),
            11.1,
            10.2,
            [
                new ForecastHour(new \DateTimeImmutable('2026-10-20T19:00', new \DateTimeZone('Europe/Paris')), 12.7, 3, false, 0),
                new ForecastHour(new \DateTimeImmutable('2026-10-20T20:00', new \DateTimeZone('Europe/Paris')), 12.1, 3, false, 0),
                new ForecastHour(new \DateTimeImmutable('2026-10-20T21:00', new \DateTimeZone('Europe/Paris')), 11.8, 2, false, 0),
                new ForecastHour(new \DateTimeImmutable('2026-10-20T22:00', new \DateTimeZone('Europe/Paris')), 11.4, 2, false, 0),
            ],
            [
                new ForecastDay('2026-10-20', 80, 10.1, 14.3, 98, '08:18', '18:51'),
                new ForecastDay('2026-10-21', 80, 7.9, 14.8, 83, '08:20', '18:49'),
                new ForecastDay('2026-10-22', 61, 8.6, 15.7, 13, '08:21', '18:47'),
            ],
        ), $forecast);
    }

    public function testQuery(): void
    {
        $this->client()->forecast(self::API, 48.85, 2.35, 3, 4);

        parse_str((string) parse_url($this->urls[0], \PHP_URL_QUERY), $query);
        self::assertStringStartsWith(self::API.'/v1/forecast?', $this->urls[0]);
        self::assertSame(['48.85', '2.35', 'Europe/Paris', '3', '4'], [$query['latitude'], $query['longitude'], $query['timezone'], $query['forecast_days'], $query['forecast_hours']]);
    }

    public function testApiErrorIsExplained(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Open-Meteo refuse la demande : Latitude must be in range of -90 to 90°.');

        $this->client(new MockResponse('{"error":true,"reason":"Latitude must be in range of -90 to 90°."}', ['http_code' => 400]))->forecast(self::API, 120, 2.35, 3, 4);
    }

    public function testUnreachable(): void
    {
        $this->expectExceptionMessage('Open-Meteo injoignable');

        $this->client(new MockResponse('', ['error' => 'Connexion refusée']))->forecast(self::API, 48.85, 2.35, 3, 4);
    }
}
