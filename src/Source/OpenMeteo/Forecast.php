<?php

namespace App\Source\OpenMeteo;

final readonly class Forecast
{
    /**
     * @param list<ForecastHour> $hours prochaines heures, la première étant l'heure en cours
     * @param list<ForecastDay>  $days  aujourd'hui puis les jours suivants
     */
    public function __construct(
        public ForecastHour $now,
        public float $feelsLike,
        /** Vent à 10 m, km/h */
        public float $wind,
        public array $hours,
        public array $days,
    ) {
    }
}
