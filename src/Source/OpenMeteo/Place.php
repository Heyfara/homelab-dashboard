<?php

namespace App\Source\OpenMeteo;

/** Un lieu trouvé par la recherche d'Open-Meteo (géocodage). */
final readonly class Place
{
    public function __construct(
        public string $name,
        /** Département, sinon région : pour distinguer les homonymes */
        public ?string $region,
        public ?string $country,
        public float $latitude,
        public float $longitude,
    ) {
    }
}
