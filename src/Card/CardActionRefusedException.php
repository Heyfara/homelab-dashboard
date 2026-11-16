<?php

namespace App\Card;

/**
 * Action d'une carte refusée pour une raison à montrer telle quelle à la
 * personne (montant invalide, session déjà en cours…) : réponse 409.
 */
final class CardActionRefusedException extends \RuntimeException
{
}
