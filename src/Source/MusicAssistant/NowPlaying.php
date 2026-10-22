<?php

namespace App\Source\MusicAssistant;

final readonly class NowPlaying
{
    public function __construct(
        public string $title,
        public ?string $artist,
        public ?string $album,
        /** Pochette : chemin /imageproxy/… sur Music Assistant (public), sinon null */
        public ?string $image,
        /** Durée du titre en secondes, null pour un flux (radio) */
        public ?int $duration,
        /** Position au moment `elapsedAt` (horodatage Unix) */
        public float $elapsed,
        public float $elapsedAt,
        /** Couleur dominante de la pochette (#rrggbb), calculée par Music Assistant */
        public ?string $accent,
    ) {
    }
}
