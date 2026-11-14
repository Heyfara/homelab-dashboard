<?php

namespace App\Controller;

use App\Dashboard\DashboardProvider;
use App\Dashboard\Model\Profile;
use App\Notifications\ProfileNotifications;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cloche : messages ntfy des sujets suivis par le profil, et ce qu'il a lu.
 */
#[Route('/api/profiles/{slug}/notifications')]
final class NotificationsController extends AbstractController
{
    use SameSiteWrites;

    /** Au plus par appel : « tout marquer comme lu » envoie toute la liste. */
    private const MAX_IDS = 1000;

    public function __construct(
        private readonly DashboardProvider $provider,
        private readonly ProfileNotifications $notifications,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(string $slug): JsonResponse
    {
        return $this->json($this->notifications->list($this->profile($slug), $this->provider->get()));
    }

    #[Route('/read', methods: ['POST'])]
    public function markRead(string $slug, Request $request): Response
    {
        self::sameSiteOnly($request);
        self::jsonOnly($request, '{"ids": ["id de message", …]}');
        $profile = $this->profile($slug);

        $ids = $request->toArray()['ids'] ?? null;
        if (!\is_array($ids) || !array_is_list($ids) || \count($ids) > self::MAX_IDS || [] !== array_filter($ids, static fn (mixed $id): bool => !\is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id))) {
            return $this->json(['error' => 'Attendu : une liste d\'identifiants de message.'], 422);
        }

        $this->notifications->markRead($profile->slug, array_values(array_unique($ids)));

        return new Response(null, 204);
    }

    private function profile(string $slug): Profile
    {
        $profile = $this->provider->get()->profile($slug) ?? throw new NotFoundHttpException(\sprintf('Profil inconnu : %s', $slug));
        if ([] === $profile->notifications) {
            throw new NotFoundHttpException(\sprintf('Pas de notifications pour le profil %s', $slug));
        }

        return $profile;
    }
}
