<?php

namespace App\Card;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;

/**
 * Un type de carte qui a, en plus de ses données principales (fetch, rythme
 * `refresh` de la carte), des flux à rafraîchir à leur propre rythme : les
 * lectures en cours de Jellyfin toutes les 10 s quand les derniers ajouts
 * suivent toutes les 5 min. Chaque flux a son cache et son appel
 * (`GET /api/cards/{id}?feed=…`).
 */
interface CardFeedsInterface
{
    /**
     * @return array<string, int> nom du flux → rafraîchissement en secondes ; vide = aucun
     */
    public function feeds(CardInstance $card): array;

    /**
     * @return array<string, mixed>
     */
    public function fetchFeed(string $feed, CardInstance $card, Dashboard $dashboard): array;
}
