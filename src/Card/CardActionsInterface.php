<?php

namespace App\Card;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;

/**
 * Un type de carte qui déclenche des actions (lancer une tâche…), par
 * `POST /api/cards/{id}/actions/{action}` (CardActionController). Les données
 * de la carte sont relues à la requête suivante.
 *
 * Écritures sans mot de passe, comme « Ma config » : n'exposer que des actions
 * qu'on accepte de voir lancées par n'importe qui sur le réseau de la maison.
 */
interface CardActionsInterface
{
    /**
     * @return list<string> actions acceptées par cette carte
     */
    public function actions(CardInstance $card): array;

    /**
     * @param array<string, mixed> $payload corps JSON de la requête
     *
     * @return array<string, mixed> réponse renvoyée au navigateur
     *
     * @throws CardActionRefusedException raison à montrer à la personne (409)
     * @throws \App\Source\SourceUnavailableException source injoignable (502)
     */
    public function runAction(string $action, array $payload, CardInstance $card, Dashboard $dashboard): array;
}
