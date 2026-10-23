<?php

namespace App\Source\Romm;

final readonly class RommGame
{
    public function __construct(
        public int $id,
        /** Nom trouvé par les métadonnées, sinon nom du fichier */
        public string $name,
        public int $platformId,
        public string $platform,
        /** Chemin de la petite jaquette sous l'adresse de RomM (public, sans authentification) */
        public ?string $cover,
        public ?\DateTimeImmutable $addedAt,
    ) {
    }
}
