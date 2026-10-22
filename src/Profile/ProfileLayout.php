<?php

namespace App\Profile;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Profile;
use App\Dashboard\Model\Section;

/**
 * Ordre des cartes choisi par une personne (mode « Réorganiser »), dans
 * `<profil>.layout.json`. Le YAML reste la référence : une carte ne change
 * jamais de section, une carte ajoutée depuis se place à la fin de sa section,
 * une carte retirée est oubliée.
 */
final readonly class ProfileLayout
{
    private const FILE = 'layout';

    public function __construct(private ProfileFiles $files)
    {
    }

    /**
     * @return list<string> identifiants des cartes, dans l'ordre choisi ; vide = ordre du YAML
     */
    public function order(string $profile): array
    {
        $order = $this->files->read($profile, self::FILE)['order'] ?? [];

        return \is_array($order) ? array_values(array_filter($order, 'is_string')) : [];
    }

    /**
     * @param list<string> $order
     */
    public function save(string $profile, array $order): void
    {
        $this->files->update($profile, self::FILE, static fn (): array => ['version' => 1, 'order' => array_values($order)]);
    }

    public function reset(string $profile): void
    {
        ProfileFiles::check($profile);
        $this->files->remove($profile, self::FILE);
    }

    public function arrange(Profile $profile): Profile
    {
        $rank = array_flip($this->order($profile->slug));
        if ([] === $rank) {
            return $profile;
        }

        return new Profile($profile->slug, $profile->name, $profile->color, array_map(static function (Section $section) use ($rank): Section {
            $cards = $section->cards;
            $yaml = array_flip(array_map(static fn (CardInstance $c): string => $c->id, $cards));
            // Cartes classées d'abord, dans l'ordre choisi ; les autres ensuite, dans l'ordre du YAML.
            usort($cards, static fn (CardInstance $a, CardInstance $b): int => [$rank[$a->id] ?? \PHP_INT_MAX, $yaml[$a->id]] <=> [$rank[$b->id] ?? \PHP_INT_MAX, $yaml[$b->id]]);

            return new Section($section->title, $section->columns, $cards);
        }, $profile->sections));
    }
}
