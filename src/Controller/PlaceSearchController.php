<?php

namespace App\Controller;

use App\Source\OpenMeteo\OpenMeteoClient;
use App\Source\OpenMeteo\Place;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final class PlaceSearchController extends AbstractController
{
    /** Recherche de ville pour le lieu de la météo (« Ma config »), par le géocodage d'Open-Meteo. */
    #[Route('/api/places', methods: ['GET'])]
    public function __invoke(OpenMeteoClient $meteo, #[MapQueryParameter] string $q = ''): JsonResponse
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return $this->json([]);
        }

        return $this->json(array_map(static fn (Place $p): array => [
            'name' => $p->name,
            'region' => $p->region,
            'country' => $p->country,
            'latitude' => $p->latitude,
            'longitude' => $p->longitude,
        ], $meteo->search(OpenMeteoClient::GEOCODING_API, $q)));
    }
}
