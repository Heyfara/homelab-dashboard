<?php

namespace App\Source\Immich;

/** Souvenir « ce jour-là » : les photos d'une année passée à la même date. */
final readonly class ImmichMemory
{
    /**
     * @param list<ImmichAsset> $assets
     */
    public function __construct(
        public string $id,
        public int $year,
        public array $assets,
    ) {
    }
}
