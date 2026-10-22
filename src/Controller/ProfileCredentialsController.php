<?php

namespace App\Controller;

use App\Dashboard\DashboardProvider;
use App\Profile\CredentialKindInterface;
use App\Profile\CredentialKinds;
use App\Profile\InvalidCredentialException;
use App\Profile\ProfileCredentials;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mes clés » : clés d'API saisies par chaque profil. Aucune réponse ne
 * contient jamais une clé. Pas de mot de passe (choix de la famille) ; les
 * écritures refusent les requêtes venues d'un autre site (CSRF).
 */
#[Route('/api/profiles/{slug}/credentials')]
final class ProfileCredentialsController extends AbstractController
{
    use SameSiteWrites;

    public function __construct(
        private readonly DashboardProvider $provider,
        private readonly CredentialKinds $kinds,
        private readonly ProfileCredentials $credentials,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(string $slug): JsonResponse
    {
        $saved = $this->credentials->list($this->profile($slug));

        return $this->json(array_values(array_map(static fn (CredentialKindInterface $k): array => [
            'id' => $k->id(),
            'label' => $k->label(),
            'help' => $k->help(),
            'configured' => isset($saved[$k->id()]),
            'account' => $saved[$k->id()]['description'] ?? null,
            'updatedAt' => $saved[$k->id()]['updatedAt'] ?? null,
        ], $this->kinds->all())));
    }

    #[Route('/{kind}', methods: ['PUT'])]
    public function save(string $slug, string $kind, Request $request): JsonResponse
    {
        self::sameSiteOnly($request);
        self::jsonOnly($request, '{"key": "…"}');
        $profile = $this->profile($slug);
        $type = $this->kind($kind);
        $key = trim((string) ($request->toArray()['key'] ?? ''));

        try {
            $account = $type->verify($key);
        } catch (InvalidCredentialException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
        $this->credentials->set($profile, $type->id(), $key, $account);

        return $this->json(['configured' => true, 'account' => $account, 'updatedAt' => $this->credentials->list($profile)[$type->id()]['updatedAt'] ?? null]);
    }

    #[Route('/{kind}', methods: ['DELETE'])]
    public function remove(string $slug, string $kind, Request $request): Response
    {
        self::sameSiteOnly($request);
        $this->credentials->remove($this->profile($slug), $this->kind($kind)->id());

        return new Response(null, 204);
    }

    private function profile(string $slug): string
    {
        if (!isset($this->provider->get()->profiles[$slug])) {
            throw new NotFoundHttpException(\sprintf('Profil inconnu : %s', $slug));
        }

        return $slug;
    }

    private function kind(string $kind): CredentialKindInterface
    {
        return $this->kinds->get($kind) ?? throw new NotFoundHttpException(\sprintf('Service inconnu dans « Mes clés » : %s', $kind));
    }
}
