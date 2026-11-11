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
     * Onglets du profil, dans l'ordre de leur première section : identifiant → nom
     * (le nom de la première section qui l'utilise).
     *
     * @return array<string, string>
     */
    public function tabs(): array
    {
        $tabs = [];
        foreach ($this->sections as $section) {
            $tabs[Section::tabSlug($section->tab)] ??= $section->tab;
        }

        return $tabs;
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
