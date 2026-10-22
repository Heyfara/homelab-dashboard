<?php

namespace App\Source\MusicAssistant;

final readonly class Speaker
{
    public function __construct(
        public string $id,
        public string $name,
        /** playing, paused ou idle */
        public string $state,
        /** Titre en cours (lecture ou pause), null à l'arrêt */
        public ?NowPlaying $playing,
    ) {
    }
}
