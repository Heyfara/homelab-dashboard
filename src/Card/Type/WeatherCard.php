<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Source\OpenMeteo\ForecastDay;
use App\Source\OpenMeteo\ForecastHour;
use App\Source\OpenMeteo\OpenMeteoClient;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Météo d'Open-Meteo : maintenant, les prochaines heures et les jours à venir.
 * Le lieu reste dans le YAML (jamais dans l'image).
 *
 *     - type: weather
 *       place: Maison
 *       latitude: 48.85
 *       longitude: 2.35
 *       span: 2
 *       rows: 2
 *       refresh: 1800
 */
#[AsCard('weather')]
final readonly class WeatherCard implements CardTypeInterface
{
    public function __construct(
        private OpenMeteoClient $meteo,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $resolver
            ->setRequired(['latitude', 'longitude'])
            ->setAllowedTypes('latitude', ['float', 'int'])
            ->setAllowedTypes('longitude', ['float', 'int'])
            ->setDefault('place', null)
            ->setAllowedTypes('place', ['null', 'string'])
            ->setInfo('place', 'nom du lieu, affiché en titre')
            ->setDefault('days', 5)
            ->setAllowedTypes('days', 'int')
            ->setAllowedValues('days', static fn (int $n): bool => $n >= 1 && $n <= 7)
            ->setDefault('hours', 12)
            ->setAllowedTypes('hours', 'int')
            ->setAllowedValues('hours', static fn (int $n): bool => $n >= 2 && $n <= 24)
            ->setDefault('timezone', 'Europe/Paris')
            ->setAllowedTypes('timezone', 'string')
            ->setDefault('api', 'https://api.open-meteo.com')
            ->setAllowedTypes('api', 'string');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return ['title' => $card->title ?? $card->options['place'] ?? 'Météo', 'place' => $card->options['place']];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $o = $card->options;
        $forecast = $this->meteo->forecast($o['api'], (float) $o['latitude'], (float) $o['longitude'], $o['days'], $o['hours'], $o['timezone']);
        $today = $forecast->days[0] ?? null;

        return [
            'now' => [
                'time' => $forecast->now->time->format('H:i'),
                'temperature' => (int) round($forecast->now->temperature),
                'feelsLike' => (int) round($forecast->feelsLike),
                'code' => $forecast->now->code,
                'isDay' => $forecast->now->isDay,
                'wind' => (int) round($forecast->wind),
            ],
            // La première heure est l'heure en cours, déjà décrite par « now ».
            'hours' => array_map(static fn (ForecastHour $h): array => [
                'time' => $h->time->format('H:i'),
                'temperature' => (int) round($h->temperature),
                'code' => $h->code,
                'isDay' => $h->isDay,
                'rainChance' => $h->rainChance,
            ], \array_slice($forecast->hours, 1)),
            'days' => array_map(static fn (ForecastDay $d): array => [
                'date' => $d->date,
                'code' => $d->code,
                'min' => (int) round($d->min),
                'max' => (int) round($d->max),
                'rainChance' => $d->rainChance,
            ], $forecast->days),
            'sun' => ['sunrise' => $today?->sunrise, 'sunset' => $today?->sunset],
        ];
    }
}
