<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Profile\WeatherLocation;
use App\Source\OpenMeteo\ForecastDay;
use App\Source\OpenMeteo\ForecastHour;
use App\Source\OpenMeteo\OpenMeteoClient;
use App\Source\SourceUnavailableException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Météo d'Open-Meteo : maintenant, les prochaines heures et les jours à venir.
 * Le lieu reste dans le YAML (jamais dans l'image) ; avec `location: profile`,
 * chaque personne peut choisir le sien dans « Ma config » (celui du YAML sert
 * alors de lieu par défaut, et devient facultatif).
 *
 *     - type: weather
 *       place: Maison
 *       latitude: 48.85
 *       longitude: 2.35
 *       location: profile   # facultatif : lieu choisi dans « Ma config »
 *       span: 2
 *       rows: 2
 *       refresh: 1800
 */
#[AsCard('weather')]
final readonly class WeatherCard implements CardTypeInterface
{
    public function __construct(
        private OpenMeteoClient $meteo,
        private WeatherLocation $location,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $resolver
            ->setDefault('location', null)
            ->setAllowedValues('location', [null, WeatherLocation::PROFILE])
            ->setInfo('location', 'profile = lieu choisi dans « Ma config » (le YAML devient le lieu par défaut)')
            ->setDefault('latitude', null)
            ->setAllowedTypes('latitude', ['null', 'float', 'int'])
            ->setDefault('longitude', null)
            ->setAllowedTypes('longitude', ['null', 'float', 'int'])
            ->setInfo('latitude', 'obligatoire, sauf avec location: profile')
            ->setNormalizer('longitude', static function (Options $o, mixed $longitude): mixed {
                if (WeatherLocation::PROFILE !== $o['location'] && (null === $o['latitude'] || null === $longitude)) {
                    throw new MissingOptionsException('Les options "latitude" et "longitude" sont obligatoires (sauf avec location: profile).');
                }

                return $longitude;
            })
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
        $place = $this->location->forCard($card)['place'] ?? null;

        return ['title' => $card->title ?? $place ?? 'Météo', 'place' => $place];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $o = $card->options;
        $where = $this->location->forCard($card) ?? throw new SourceUnavailableException('Pas encore de lieu pour ce profil : le choisir dans « Ma config ».');
        $forecast = $this->meteo->forecast($o['api'], (float) $where['latitude'], (float) $where['longitude'], $o['days'], $o['hours'], $o['timezone']);
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
