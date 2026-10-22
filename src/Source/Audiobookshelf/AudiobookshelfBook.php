<?php

namespace App\Source\Audiobookshelf;

final readonly class AudiobookshelfBook
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $author,
        /** secondes */
        public int $duration,
        public ?\DateTimeImmutable $addedAt,
    ) {
    }
}
