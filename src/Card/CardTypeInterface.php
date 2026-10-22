<?php

namespace App\Card;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un type de carte côté serveur. Voir docs/ajouter-une-carte.md.
 */
interface CardTypeInterface
{
    /**
     * Déclare les options acceptées dans le YAML et leurs valeurs par défaut.
     * $dashboard donne accès au catalogue (services, machines) pour valider les renvois.
     */
    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void;

    /**
     * Props fixes envoyées avec la mise en page : rien qui demande un appel réseau.
     * $card->options sont déjà résolues.
     *
     * @return array<string, mixed>
     */
    public function describe(CardInstance $card, Dashboard $dashboard): array;

    /**
     * Données vivantes, mises en cache $card->refresh secondes par CardDataFetcher.
     * Une exception devient un CardResult en erreur ; elle n'est pas mise en cache.
     *
     * @return array<string, mixed>
     */
    public function fetch(CardInstance $card, Dashboard $dashboard): array;
}
