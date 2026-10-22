<?php

namespace App\Controller;

use App\Dashboard\DashboardProvider;
use App\Dashboard\Model\Profile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    /** Profils proposés sur l'écran d'accueil. */
    #[Route('/api/profiles', methods: ['GET'])]
    public function __invoke(DashboardProvider $provider): JsonResponse
    {
        return $this->json(array_values(array_map(
            static fn (Profile $p): array => ['slug' => $p->slug, 'name' => $p->name, 'color' => $p->color],
            $provider->get()->profiles,
        )));
    }
}
