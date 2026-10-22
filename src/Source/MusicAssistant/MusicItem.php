<?php

namespace App\Source\MusicAssistant;

final readonly class MusicItem
{
    public function __construct(
        public string $name,
        /** track, album, artist, playlist, radio… */
        public string $type,
        /** Chemin /imageproxy/… sur Music Assistant (public), sinon null */
        public ?string $image,
    ) {
    }
}
