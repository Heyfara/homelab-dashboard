<?php

namespace App\Source\Romm;

final readonly class RommPlatform
{
    public function __construct(
        public int $id,
        public string $slug,
        /** Nom personnalisé s'il y en a un */
        public string $name,
        public int $games,
    ) {
    }
}
