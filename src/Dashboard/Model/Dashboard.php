<?php

namespace App\Dashboard\Model;

final readonly class Dashboard
{
    /**
     * @param array<string, Host>              $hosts
     * @param array<string, ServiceDefinition> $services
     * @param array<string, Profile>           $profiles
     */
    public function __construct(
        public Settings $settings,
        public array $hosts,
        public array $services,
        public array $profiles,
    ) {
    }

    public function profile(string $slug): ?Profile
    {
        return $this->profiles[$slug] ?? null;
    }

    public function card(string $id): ?CardInstance
    {
        foreach ($this->cards() as $card) {
            if ($card->id === $id) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @return iterable<CardInstance>
     */
    public function cards(): iterable
    {
        foreach ($this->profiles as $profile) {
            yield from $profile->cards();
        }
    }

    /**
     * Copie du dashboard où chaque carte est remplacée par $map($card).
     *
     * @param callable(CardInstance): CardInstance $map
     */
    public function mapCards(callable $map): self
    {
        $profiles = [];
        foreach ($this->profiles as $slug => $profile) {
            $sections = [];
            foreach ($profile->sections as $section) {
                $sections[] = new Section($section->title, $section->columns, array_map($map, $section->cards));
            }
            $profiles[$slug] = new Profile($profile->slug, $profile->name, $profile->color, $sections);
        }

        return new self($this->settings, $this->hosts, $this->services, $profiles);
    }
}
