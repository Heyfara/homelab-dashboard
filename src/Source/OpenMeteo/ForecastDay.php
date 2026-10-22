<?php

namespace App\Source\OpenMeteo;

final readonly class ForecastDay
{
    public function __construct(
        /** AAAA-MM-JJ, date locale du lieu */
        public string $date,
        public int $code,
        public float $min,
        public float $max,
        public ?int $rainChance,
        /** HH:MM, heure locale */
        public ?string $sunrise,
        public ?string $sunset,
    ) {
    }
}
