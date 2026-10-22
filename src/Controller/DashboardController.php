<?php

namespace App\Controller;

use App\Card\CardTypeRegistry;
use App\Dashboard\DashboardProvider;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Section;
use App\Profile\ProfileLayout;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    /** Mise en page d'un profil (dans l'ordre choisi par la personne), avec les props fixes de chaque carte. */
    #[Route('/api/dashboards/{profile}', methods: ['GET'])]
    public function __invoke(string $profile, DashboardProvider $provider, CardTypeRegistry $registry, ProfileLayout $layout): JsonResponse
    {
        $dashboard = $provider->get();
        $found = $dashboard->profile($profile) ?? throw $this->createNotFoundException(\sprintf('Profil inconnu : %s', $profile));
        $customized = [] !== $layout->order($found->slug);
        $found = $layout->arrange($found);

        return $this->json([
            'profile' => ['slug' => $found->slug, 'name' => $found->name, 'color' => $found->color],
            'customized' => $customized,
            'settings' => ['columns' => $dashboard->settings->columns, 'refresh' => $dashboard->settings->refresh],
            'sections' => array_map(static fn (Section $section): array => [
                'title' => $section->title,
                'columns' => $section->columns,
                'cards' => array_map(static fn (CardInstance $card): array => [
                    'id' => $card->id,
                    'type' => $card->type,
                    'title' => $card->title,
                    'span' => $card->span,
                    'rows' => $card->rows,
                    'refresh' => $card->refresh,
                    'feeds' => (object) $registry->feeds($card),
                    'props' => $registry->get($card->type)->describe($card, $dashboard),
                ], $section->cards),
            ], $found->sections),
        ]);
    }
}
