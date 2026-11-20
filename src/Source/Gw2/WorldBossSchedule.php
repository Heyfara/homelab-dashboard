<?php

namespace App\Source\Gw2;

/**
 * Horaire des boss du monde de Tyrie (hors boss difficiles : Tequatl, Triple
 * Trouble, Reine Karka). L'API n'en donne pas : il est recopié du tableau
 * « Full schedule » de https://wiki.guildwars2.com/wiki/World_boss (relevé le
 * 2026-11-16, page modifiée le 2026-09-02). Le même chaque jour, en UTC : un
 * boss par quart d'heure, chacun toutes les 2 h ou toutes les 3 h. Zones et
 * points de passage : même source (Widget:Event_timer/data.json, World boss).
 */
final class WorldBossSchedule
{
    /** Durée pendant laquelle un boss est considéré « en cours » après son apparition (approximation). */
    public const ACTIVE_MINUTES = 15;

    /**
     * Identifiant de /v2/worldbosses → [nom français (wiki FR), zone (wiki FR),
     * point de passage (code de chat, Widget:Event_timer/data.json du wiki),
     * portrait (miniature du wiki, null quand il n'y en a pas),
     * 1er passage en minutes après 0 h UTC, intervalle en minutes].
     *
     * @var array<string, array{string, string, string, ?string, int, int}>
     */
    private const BOSSES = [
        'admiral_taidha_covington' => ['Amiral Taidha Covington', 'Côte de la marée sanglante', '[&BKgBAAA=]', 'https://wiki.guildwars2.com/images/thumb/8/8d/Admiral_Taidha_Covington.jpg/128px-Admiral_Taidha_Covington.jpg', 0, 180],
        'svanir_shaman_chief' => ['Chef chamane de Svanir', 'Contreforts du voyageur', '[&BMIDAAA=]', 'https://wiki.guildwars2.com/images/thumb/d/d5/Svanir_Shaman_Chief_%28empowered%29.jpg/128px-Svanir_Shaman_Chief_%28empowered%29.jpg', 15, 120],
        'megadestroyer' => ['Mégadestructeur', 'Mont Maelström', '[&BM0CAAA=]', null, 30, 180],
        'fire_elemental' => ['Élémentaire de feu', 'Province de Metrica', '[&BEcAAAA=]', 'https://wiki.guildwars2.com/images/thumb/4/44/Greater_Fire_Elemental_portrait.png/128px-Greater_Fire_Elemental_portrait.png', 45, 120],
        'the_shatterer' => ['Le Destructeur', 'Les Steppes de la strie flamboyante', '[&BE4DAAA=]', 'https://wiki.guildwars2.com/images/thumb/3/30/The_Shatterer_portrait.png/128px-The_Shatterer_portrait.png', 60, 180],
        'great_jungle_wurm' => ['Grande guivre de la jungle', 'Forêt de Caledon', '[&BEEFAAA=]', 'https://wiki.guildwars2.com/images/thumb/4/40/Great_Jungle_Wurm_portrait.png/128px-Great_Jungle_Wurm_portrait.png', 75, 120],
        'modniir_ulgoth' => ['Ulgoth le Modniir', 'Hinterlands harathis', '[&BLAAAAA=]', null, 90, 180],
        'shadow_behemoth' => ['Béhémoth des ombres', 'La Vallée de la reine', '[&BPcAAAA=]', 'https://wiki.guildwars2.com/images/thumb/b/b3/Shadow_Behemoth_portrait.png/128px-Shadow_Behemoth_portrait.png', 105, 120],
        'inquest_golem_mark_ii' => ['Golem de l\'Enqueste Marque II', 'Mont Maelström', '[&BNQCAAA=]', 'https://wiki.guildwars2.com/images/thumb/4/40/Inquest_Golem_Mark_II_portrait.png/128px-Inquest_Golem_Mark_II_portrait.png', 120, 180],
        'claw_of_jormag' => ['Griffe de Jormag', 'Détroit des gorges glacées', '[&BHoCAAA=]', 'https://wiki.guildwars2.com/images/thumb/8/88/Claw_of_Jormag_portrait.png/128px-Claw_of_Jormag_portrait.png', 150, 180],
    ];

    /**
     * Les prochains passages, en commençant par celui en cours s'il y en a un.
     *
     * @return list<array{id: string, name: string, startsAt: \DateTimeImmutable}>
     */
    public static function upcoming(\DateTimeImmutable $now, int $limit): array
    {
        $quarter = 15 * 60;
        // 1er quart d'heure dont le boss n'est pas encore fini
        $start = (intdiv($now->getTimestamp() - self::ACTIVE_MINUTES * 60, $quarter) + 1) * $quarter;

        $slots = [];
        for ($t = $start; \count($slots) < $limit; $t += $quarter) {
            $minute = intdiv($t % 86400, 60);
            foreach (self::BOSSES as $id => [$name, , , , $first, $interval]) {
                if ($minute >= $first && 0 === ($minute - $first) % $interval) {
                    $slots[] = ['id' => $id, 'name' => $name, 'startsAt' => new \DateTimeImmutable('@'.$t)->setTimezone(new \DateTimeZone('UTC'))];
                }
            }
        }

        return $slots;
    }

    /**
     * Fiche de chaque boss, dans l'ordre de ids().
     *
     * @return array<string, array{name: string, zone: string, waypoint: string, image: ?string}>
     */
    public static function bosses(): array
    {
        return array_map(static fn (array $b): array => ['name' => $b[0], 'zone' => $b[1], 'waypoint' => $b[2], 'image' => $b[3]], self::BOSSES);
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::BOSSES);
    }
}
