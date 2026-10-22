<?php

namespace App\Source\OpenMeteo;

final readonly class ForecastHour
{
    public function __construct(
        /** Heure locale du lieu */
        public \DateTimeImmutable $time,
        public float $temperature,
        /** Code météo WMO (0 : ciel clair … 99 : orage avec grêle) */
        public int $code,
        public bool $isDay,
        /** Probabilité de précipitations en %, null pour les conditions actuelles */
        public ?int $rainChance,
    ) {
    }
}
