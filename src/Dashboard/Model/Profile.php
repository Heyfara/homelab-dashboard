<?php

namespace App\Dashboard\Model;

final readonly class Profile
{
    /** Couleurs reconnues par le front (palette Tailwind). */
    public const COLORS = ['slate', 'indigo', 'sky', 'emerald', 'amber', 'rose', 'violet', 'teal'];

    /**
     * @param list<Section> $sections
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $color,
        public array $sections,
    ) {
    }

    /**
     * @return iterable<CardInstance>
     */
    public function cards(): iterable
    {
        foreach ($this->sections as $section) {
            yield from $section->cards;
        }
    }
}
