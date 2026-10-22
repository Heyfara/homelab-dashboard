<?php

namespace App\Source\CalibreWeb;

final readonly class CalibreWebBook
{
    public function __construct(
        public int $id,
        public string $title,
        /** Auteurs séparés par des virgules */
        public ?string $author,
        public bool $hasCover,
        public ?\DateTimeImmutable $addedAt,
    ) {
    }
}
