<?php

namespace App\Controller;

use App\Dashboard\DashboardProvider;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Profile;
use App\Profile\ProfileLayout;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ordre des cartes choisi par chaque profil (mode « Réorganiser »). Lu avec le
 * dashboard (DashboardController) ; ici, seulement l'enregistrer ou l'oublier.
 */
#[Route('/api/profiles/{slug}/layout')]
final class ProfileLayoutController extends AbstractController
{
    use SameSiteWrites;

    public function __construct(
        private readonly DashboardProvider $provider,
        private readonly ProfileLayout $layout,
    ) {
    }

    #[Route('', methods: ['PUT'])]
    public function save(string $slug, Request $request): Response
    {
        self::sameSiteOnly($request);
        self::jsonOnly($request, '{"order": ["id de carte", …]}');
        $profile = $this->profile($slug);

        $order = $request->toArray()['order'] ?? null;
        if (!\is_array($order) || !array_is_list($order) || [] !== array_filter($order, static fn (mixed $id): bool => !\is_string($id))) {
            return $this->json(['error' => 'Ordre attendu : une liste d\'identifiants de carte.'], 422);
        }
        $known = array_map(static fn (CardInstance $c): string => $c->id, iterator_to_array($profile->cards(), false));
        $unknown = array_diff($order, $known);
        if ([] !== $unknown) {
            return $this->json(['error' => \sprintf('Cartes inconnues dans ce profil : %s', implode(', ', $unknown))], 422);
        }

        $this->layout->save($slug, array_values(array_unique($order)));

        return new Response(null, 204);
    }

    #[Route('', methods: ['DELETE'])]
    public function reset(string $slug, Request $request): Response
    {
        self::sameSiteOnly($request);
        $this->layout->reset($this->profile($slug)->slug);

        return new Response(null, 204);
    }

    private function profile(string $slug): Profile
    {
        return $this->provider->get()->profile($slug) ?? throw new NotFoundHttpException(\sprintf('Profil inconnu : %s', $slug));
    }
}
