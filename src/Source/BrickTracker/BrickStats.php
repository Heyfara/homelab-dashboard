<?php

namespace App\Source\BrickTracker;

final readonly class BrickStats
{
    public function __construct(
        public int $sets,
        public int $parts,
        public int $minifigures,
        public int $missingParts,
        public int $damagedParts,
    ) {
    }
}
