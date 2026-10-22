<?php

namespace App\Dashboard;

use App\Card\CardTypeRegistry;
use App\Card\UnknownCardTypeException;
use App\Dashboard\Model\Dashboard;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface as OptionsException;

/**
 * Ce que l'arbre de configuration ne peut pas vérifier : les renvois entre
 * parties du fichier et les options propres à chaque type de carte.
 */
final readonly class DashboardValidator
{
    public function __construct(
        private CardTypeRegistry $registry,
    ) {
    }

    /**
     * @return list<string> toutes les erreurs, vide si la config est valide
     */
    public function validate(Dashboard $dashboard): array
    {
        $errors = [];

        foreach ($dashboard->services as $service) {
            if (null !== $service->host && !isset($dashboard->hosts[$service->host])) {
                $errors[] = \sprintf(
                    'Service « %s » : machine inconnue « %s » (machines déclarées : %s).',
                    $service->id,
                    $service->host,
                    implode(', ', array_keys($dashboard->hosts)) ?: 'aucune',
                );
            }
        }

        $seen = [];
        foreach ($dashboard->profiles as $profile) {
            foreach ($profile->sections as $section) {
                foreach ($section->cards as $card) {
                    if (isset($seen[$card->id]) && 1 === $seen[$card->id]++) {
                        $errors[] = \sprintf('Identifiant de carte « %s » utilisé plusieurs fois.', $card->id);
                    }
                    $seen[$card->id] ??= 1;

                    try {
                        $this->registry->resolveOptions($card, $dashboard);
                    } catch (UnknownCardTypeException|OptionsException $e) {
                        $errors[] = \sprintf('Carte %s (profil %s, section « %s ») : %s', $card->id, $profile->slug, $section->title, $e->getMessage());
                    }
                }
            }
        }

        return $errors;
    }
}
