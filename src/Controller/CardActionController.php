<?php

namespace App\Controller;

use App\Card\CardActionRefusedException;
use App\Card\CardActionsInterface;
use App\Card\CardDataFetcher;
use App\Card\CardTypeRegistry;
use App\Dashboard\DashboardProvider;
use App\Source\SourceUnavailableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CardActionController extends AbstractController
{
    use SameSiteWrites;

    /**
     * Action d'une carte (CardActionsInterface), corps JSON propre à l'action.
     * 409 : refusée, avec la raison ; 502 : source injoignable. Les données de
     * la carte sont oubliées dans tous les cas, pour montrer le nouvel état.
     */
    #[Route('/api/cards/{id}/actions/{action}', methods: ['POST'])]
    public function __invoke(string $id, string $action, Request $request, DashboardProvider $provider, CardTypeRegistry $registry, CardDataFetcher $fetcher): JsonResponse
    {
        self::sameSiteOnly($request);
        self::jsonOnly($request, 'objet JSON propre à l\'action');

        $dashboard = $provider->get();
        $card = $dashboard->card($id) ?? throw $this->createNotFoundException(\sprintf('Carte inconnue : %s', $id));
        if (!\in_array($action, $registry->actions($card), true)) {
            throw $this->createNotFoundException(\sprintf('Action inconnue pour la carte %s : %s', $id, $action));
        }
        $type = $registry->get($card->type);
        \assert($type instanceof CardActionsInterface);

        try {
            return $this->json($type->runAction($action, $request->toArray(), $card, $dashboard));
        } catch (CardActionRefusedException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        } catch (SourceUnavailableException $e) {
            return $this->json(['error' => $e->getMessage()], 502);
        } finally {
            $fetcher->forget($card);
        }
    }
}
