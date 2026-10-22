<?php

namespace App\Source\C411;

use App\Source\SourceUnavailableException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Profil du compte sur C411 (GET /api/user/profile, clé avec le scope
 * « Profil (lecture) »). Limite : 30 requêtes par minute et par clé, partagées
 * avec Torznab ; C411 ne rafraîchit pas ces données plus d'une fois par 30 s.
 * Le profil est donc gardé 30 s, et un 429 suspend les appels une minute.
 */
final readonly class C411Client
{
    private const PROFILE_TTL = 30;
    private const BACKOFF_TTL = 60;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $cache,
        #[Autowire('%env(C411_API_SECRET)%')]
        #[\SensitiveParameter]
        private string $apiKey,
    ) {
    }

    /**
     * Réponse brute de l'API (voir docs/configuration.md, carte c411).
     *
     * @return array<string, mixed>
     */
    public function profile(string $baseUrl): array
    {
        if ('' === $this->apiKey) {
            throw new SourceUnavailableException('Clé API C411 absente (C411_API_SECRET)');
        }

        $key = 'c411.'.hash('xxh128', $baseUrl.'|'.$this->apiKey);
        $backoff = $this->cache->getItem($key.'.backoff');
        if ($backoff->isHit()) {
            throw new SourceUnavailableException((string) $backoff->get());
        }
        $cached = $this->cache->getItem($key.'.profile');
        if ($cached->isHit()) {
            return $cached->get();
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/').'/api/user/profile', [
                'headers' => ['Authorization' => 'Bearer '.$this->apiKey, 'Accept' => 'application/json'],
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();

            if (200 !== $status) {
                $message = match ($status) {
                    401 => 'Clé API C411 refusée (401), vérifier C411_API_SECRET',
                    403 => 'La clé C411 n\'a pas le scope « Profil (lecture) » (403) : en créer une nouvelle en le cochant',
                    429 => 'Limite de l\'API C411 atteinte (429) ; nouvel essai dans une minute',
                    default => \sprintf('C411 a répondu %d', $status),
                };
                if (429 === $status) {
                    $this->cache->save($backoff->set($message)->expiresAfter(self::BACKOFF_TTL));
                }

                throw new SourceUnavailableException($message);
            }

            $profile = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('C411 injoignable : %s', $e->getMessage()), previous: $e);
        }

        $this->cache->save($cached->set($profile)->expiresAfter(self::PROFILE_TTL));

        return $profile;
    }
}
