<?php

namespace App\Dashboard\Model;

final readonly class Section
{
    /**
     * @param list<CardInstance> $cards
     */
    public function __construct(
        public string $title,
        public int $columns,
        public array $cards,
    ) {
    }
}
