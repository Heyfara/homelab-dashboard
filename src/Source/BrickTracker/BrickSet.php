<?php

namespace App\Source\BrickTracker;

final readonly class BrickSet
{
    public function __construct(
        /** Identifiant BrickTracker (UUID), pour le lien vers la fiche */
        public string $id,
        /** Numéro Rebrickable : 10328-1 */
        public string $number,
        public string $name,
        public ?string $theme,
        public int $parts,
        public int $minifigures,
        /** Chemin de l'image sur BrickTracker (/data/sets/…), servie sans connexion */
        public string $image,
    ) {
    }
}
