<?php

namespace App\Source\OpenMeteo;

use App\Source\SourceUnavailableException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Prévisions d'Open-Meteo (https://open-meteo.com) : gratuit, sans clé, pour un
 * usage non commercial. Heures et dates rendues dans le fuseau demandé.
 */
final readonly class OpenMeteoClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function forecast(string $api, float $latitude, float $longitude, int $days, int $hours, string $timezone = 'Europe/Paris'): Forecast
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($api, '/').'/v1/forecast', [
                'query' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'timezone' => $timezone,
                    'forecast_days' => $days,
                    'forecast_hours' => $hours,
                    'current' => 'temperature_2m,apparent_temperature,weather_code,is_day,wind_speed_10m',
                    'hourly' => 'temperature_2m,precipitation_probability,weather_code,is_day',
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
                ],
                'timeout' => 5,
            ]);
            $json = $response->toArray(false);
            if ($response->getStatusCode() >= 400) {
                throw new SourceUnavailableException(\sprintf('Open-Meteo refuse la demande : %s', $json['reason'] ?? 'HTTP '.$response->getStatusCode()));
            }
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Open-Meteo injoignable : %s', $e->getMessage()), previous: $e);
        }

        $zone = new \DateTimeZone($json['timezone'] ?? $timezone);
        $time = static fn (string $local): \DateTimeImmutable => new \DateTimeImmutable($local, $zone);
        $clock = static fn (?string $local): ?string => null === $local ? null : substr($local, 11, 5);
        $current = $json['current'];
        $hourly = $json['hourly'];
        $daily = $json['daily'];

        return new Forecast(
            new ForecastHour($time($current['time']), (float) $current['temperature_2m'], (int) $current['weather_code'], 1 === (int) $current['is_day'], null),
            (float) $current['apparent_temperature'],
            (float) $current['wind_speed_10m'],
            array_map(static fn (int $i): ForecastHour => new ForecastHour(
                $time($hourly['time'][$i]),
                (float) $hourly['temperature_2m'][$i],
                (int) $hourly['weather_code'][$i],
                1 === (int) $hourly['is_day'][$i],
                isset($hourly['precipitation_probability'][$i]) ? (int) $hourly['precipitation_probability'][$i] : null,
            ), array_keys($hourly['time'])),
            array_map(static fn (int $i): ForecastDay => new ForecastDay(
                $daily['time'][$i],
                (int) $daily['weather_code'][$i],
                (float) $daily['temperature_2m_min'][$i],
                (float) $daily['temperature_2m_max'][$i],
                isset($daily['precipitation_probability_max'][$i]) ? (int) $daily['precipitation_probability_max'][$i] : null,
                $clock($daily['sunrise'][$i] ?? null),
                $clock($daily['sunset'][$i] ?? null),
            ), array_keys($daily['time'])),
        );
    }
}
