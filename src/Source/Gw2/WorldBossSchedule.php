<?php

namespace App\Source\Gw2;

/**
 * Horaire des boss du monde de Tyrie (hors boss difficiles : Tequatl, Triple
 * Trouble, Reine Karka). L'API n'en donne pas : il est recopié du tableau
 * « Full schedule » de https://wiki.guildwars2.com/wiki/World_boss (relevé le
 * 2026-11-16, page modifiée le 2026-09-02). Le même chaque jour, en UTC : un
 * boss par quart d'heure, chacun toutes les 2 h ou toutes les 3 h.
 */
final class WorldBossSchedule
{
    /** Durée pendant laquelle un boss est considéré « en cours » après son apparition (approximation). */
    public const ACTIVE_MINUTES = 15;

    /**
     * Identifiant de /v2/worldbosses → [nom français (wiki FR), 1er passage en minutes après 0 h UTC, intervalle en minutes].
     *
     * @var array<string, array{string, int, int}>
     */
    private const BOSSES = [
        'admiral_taidha_covington' => ['Amiral Taidha Covington', 0, 180],
        'svanir_shaman_chief' => ['Chef chamane de Svanir', 15, 120],
        'megadestroyer' => ['Mégadestructeur', 30, 180],
        'fire_elemental' => ['Élémentaire de feu', 45, 120],
        'the_shatterer' => ['Le Destructeur', 60, 180],
        'great_jungle_wurm' => ['Grande guivre de la jungle', 75, 120],
        'modniir_ulgoth' => ['Ulgoth le Modniir', 90, 180],
        'shadow_behemoth' => ['Béhémoth des ombres', 105, 120],
        'inquest_golem_mark_ii' => ['Golem de l\'Enqueste Marque II', 120, 180],
        'claw_of_jormag' => ['Griffe de Jormag', 150, 180],
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
            foreach (self::BOSSES as $id => [$name, $first, $interval]) {
                if ($minute >= $first && 0 === ($minute - $first) % $interval) {
                    $slots[] = ['id' => $id, 'name' => $name, 'startsAt' => new \DateTimeImmutable('@'.$t)->setTimezone(new \DateTimeZone('UTC'))];
                }
            }
        }

        return $slots;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::BOSSES);
    }
}
