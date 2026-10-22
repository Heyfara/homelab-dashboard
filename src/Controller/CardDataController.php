<?php

namespace App\Controller;

use App\Card\CardDataFetcher;
use App\Card\CardTypeRegistry;
use App\Dashboard\DashboardProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CardDataController extends AbstractController
{
    /**
     * Données vivantes d'une carte, appelées par le front toutes les `refresh`
     * secondes ; `?feed=…` pour un de ses flux, à son propre rythme.
     */
    #[Route('/api/cards/{id}', methods: ['GET'])]
    public function __invoke(string $id, Request $request, DashboardProvider $provider, CardDataFetcher $fetcher, CardTypeRegistry $registry): JsonResponse
    {
        $dashboard = $provider->get();
        $card = $dashboard->card($id) ?? throw $this->createNotFoundException(\sprintf('Carte inconnue : %s', $id));

        $feed = $request->query->get('feed');
        if (null !== $feed && !isset($registry->feeds($card)[$feed])) {
            throw $this->createNotFoundException(\sprintf('Flux inconnu pour la carte %s : %s', $id, $feed));
        }

        return $this->json($fetcher->fetch($card, $dashboard, $feed));
    }
}
