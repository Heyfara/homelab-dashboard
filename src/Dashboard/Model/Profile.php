<?php

namespace App\Dashboard\Model;

final readonly class Profile
{
    /** Couleurs reconnues par le front (palette Tailwind). */
    public const COLORS = ['slate', 'indigo', 'sky', 'emerald', 'amber', 'rose', 'violet', 'teal'];

    /**
     * @param list<Section> $sections
     * @param list<string>  $notifications sujets ntfy suivis (cloche)
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $color,
        public array $sections,
        public array $notifications = [],
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
     * Le même profil avec d'autres sections (ordre choisi, cartes complétées…).
     *
     * @param list<Section> $sections
     */
    public function withSections(array $sections): self
    {
        return new self($this->slug, $this->name, $this->color, $sections, $this->notifications);
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
