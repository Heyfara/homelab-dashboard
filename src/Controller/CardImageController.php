<?php

namespace App\Controller;

use App\Card\Image\CardImageFetcher;
use App\Card\Image\InvalidImageRequestException;
use App\Dashboard\DashboardProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CardImageController extends AbstractController
{
    /** Image protégée d'une carte (Immich, Calibre-Web), relayée par le serveur. Adresse signée. */
    #[Route('/api/cards/{id}/image', methods: ['GET'])]
    public function __invoke(string $id, Request $request, DashboardProvider $provider, CardImageFetcher $fetcher): Response
    {
        $dashboard = $provider->get();
        $card = $dashboard->card($id) ?? throw $this->createNotFoundException(\sprintf('Carte inconnue : %s', $id));

        try {
            $image = $fetcher->fetch($card, $dashboard, $request->query->getString('path'), $request->query->getString('sig'));
        } catch (InvalidImageRequestException $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }

        return new Response($image->bytes, 200, [
            'Content-Type' => $image->contentType,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
