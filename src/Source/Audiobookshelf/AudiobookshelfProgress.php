<?php

namespace App\Source\Audiobookshelf;

final readonly class AudiobookshelfProgress
{
    public function __construct(
        public string $itemId,
        /** 0 à 1 */
        public float $progress,
        /** secondes écoutées */
        public int $currentTime,
        /** secondes */
        public int $duration,
        public \DateTimeImmutable $lastUpdate,
    ) {
    }
}
