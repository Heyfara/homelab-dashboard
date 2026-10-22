<?php

namespace App\Card\Image;

final readonly class CardImage
{
    public function __construct(
        public string $bytes,
        public string $contentType,
    ) {
    }
}
