<?php

namespace App\Controller;

use App\Dashboard\DashboardProvider;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Profile;
use App\Profile\CredentialKindInterface;
use App\Profile\CredentialKinds;
use App\Profile\InvalidSettingException;
use App\Profile\ProfileCredentials;
use App\Profile\WeatherLocation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Ma config » : ce que chaque profil règle pour ses propres cartes, une
 * section par carte configurable (clés d'API, lieu de la météo). Les clés
 * s'enregistrent par ProfileCredentialsController ; jamais renvoyées ici.
 */
#[Route('/api/profiles/{slug}/config')]
final class ProfileConfigController extends AbstractController
{
    use SameSiteWrites;

    public function __construct(
        private readonly DashboardProvider $provider,
        private readonly CredentialKinds $kinds,
        private readonly ProfileCredentials $credentials,
        private readonly WeatherLocation $weather,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $profile = $this->profile($slug);
        $saved = $this->credentials->list($profile->slug);
        $weather = $this->weather->configurableCard($profile);

        return $this->json([
            'credentials' => array_values(array_map(static fn (CredentialKindInterface $k): array => [
                'id' => $k->id(),
                'label' => $k->label(),
                'help' => $k->help(),
                'configured' => isset($saved[$k->id()]),
                'account' => $saved[$k->id()]['description'] ?? null,
                'updatedAt' => $saved[$k->id()]['updatedAt'] ?? null,
            ], $this->kinds->usedBy($profile))),
            'weather' => null === $weather ? null : $this->weather->view($weather),
        ]);
    }

    #[Route('/weather', methods: ['PUT'])]
    public function saveWeather(string $slug, Request $request): JsonResponse
    {
        self::sameSiteOnly($request);
        self::jsonOnly($request, '{"place": "…", "latitude": 48.85, "longitude": 2.35}');
        $card = $this->weatherCard($slug);

        try {
            $this->weather->save($slug, $request->toArray());
        } catch (InvalidSettingException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json($this->weather->view($card));
    }

    #[Route('/weather', methods: ['DELETE'])]
    public function resetWeather(string $slug, Request $request): Response
    {
        self::sameSiteOnly($request);
        $this->weatherCard($slug);
        $this->weather->reset($slug);

        return new Response(null, 204);
    }

    private function profile(string $slug): Profile
    {
        return $this->provider->get()->profile($slug) ?? throw new NotFoundHttpException(\sprintf('Profil inconnu : %s', $slug));
    }

    private function weatherCard(string $slug): CardInstance
    {
        return $this->weather->configurableCard($this->profile($slug)) ?? throw new NotFoundHttpException('Pas de carte météo dont le lieu se choisit dans ce profil (option location: profile).');
    }
}
