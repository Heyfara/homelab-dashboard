<?php

namespace App\Source\Freebox;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * API Freebox OS (v8), en lecture seule.
 *
 * Accès par jeton d'application : demandé une seule fois et validé sur l'écran
 * de la box (voir docs/configuration.md). L'application n'a besoin d'aucune
 * permission pour ce qu'on lit ici. Chaque session se signe par
 * HMAC-SHA1(jeton, défi) ; elle est gardée en cache et refaite quand la box
 * répond `auth_required`.
 */
final readonly class FreeboxClient
{
    public const APP_ID = 'homelab.dashboard';
    private const SESSION_TTL = 1800;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire('%env(FREEBOX_URL)%')]
        private string $url,
        #[Autowire('%env(FREEBOX_APP_TOKEN)%')]
        #[\SensitiveParameter]
        private string $appToken,
    ) {
    }

    /**
     * @return list<FreeboxHost>
     */
    public function hosts(): array
    {
        return array_map(static function (array $h): FreeboxHost {
            $ipv4 = array_values(array_filter($h['l3connectivities'] ?? [], static fn (array $c): bool => 'ipv4' === ($c['af'] ?? null)));
            usort($ipv4, static fn (array $a, array $b): int => [$b['active'] ?? false, $b['last_activity'] ?? 0] <=> [$a['active'] ?? false, $a['last_activity'] ?? 0]);
            $last = (int) ($h['last_activity'] ?? 0);
            $first = (int) ($h['first_activity'] ?? 0);

            return new FreeboxHost(
                mac: strtoupper((string) ($h['l2ident']['id'] ?? '')),
                name: '' !== ($h['primary_name'] ?? '') ? $h['primary_name'] : null,
                vendor: '' !== ($h['vendor_name'] ?? '') ? $h['vendor_name'] : null,
                type: (string) ($h['host_type'] ?? 'other'),
                ipv4: $ipv4[0]['addr'] ?? null,
                active: (bool) ($h['active'] ?? false),
                lastActivity: $last > 0 ? new \DateTimeImmutable('@'.$last) : null,
                firstActivity: $first > 0 ? new \DateTimeImmutable('@'.$first) : null,
            );
        }, $this->get('/lan/browser/pub/') ?? []);
    }

    /**
     * @return array<string, string> MAC (majuscules) → IPv4 réservée
     */
    public function staticLeases(): array
    {
        $leases = [];
        foreach ($this->get('/dhcp/static_lease/') ?? [] as $lease) {
            $leases[strtoupper((string) $lease['mac'])] = (string) $lease['ip'];
        }

        return $leases;
    }

    public function connection(): FreeboxConnection
    {
        $c = $this->get('/connection/') ?? [];

        return new FreeboxConnection(
            (string) ($c['state'] ?? 'down'),
            (string) ($c['media'] ?? ''),
            (int) ($c['rate_down'] ?? 0),
            (int) ($c['rate_up'] ?? 0),
            intdiv((int) ($c['bandwidth_down'] ?? 0), 8),
            intdiv((int) ($c['bandwidth_up'] ?? 0), 8),
        );
    }

    /**
     * Demande un jeton d'application, à faire accepter sur l'écran de la box.
     *
     * @return array{token: string, track: int}
     */
    public function requestAuthorization(string $deviceName): array
    {
        $data = $this->call('POST', '/login/authorize/', ['json' => [
            'app_id' => self::APP_ID,
            'app_name' => 'Homelab Dashboard',
            'app_version' => '1',
            'device_name' => $deviceName,
        ]]);
        if (true !== ($data['success'] ?? false)) {
            throw new SourceUnavailableException(\sprintf('Demande refusée par la Freebox (%s)', $data['error_code'] ?? '?'));
        }

        return ['token' => (string) $data['result']['app_token'], 'track' => (int) $data['result']['track_id']];
    }

    /**
     * @return string pending, granted, denied, timeout ou unknown
     */
    public function authorizationStatus(int $track): string
    {
        return (string) ($this->call('GET', '/login/authorize/'.$track)['result']['status'] ?? 'unknown');
    }

    /**
     * @return array<mixed>|null le champ `result` (absent quand la liste est vide)
     */
    private function get(string $path): ?array
    {
        $data = $this->call('GET', $path, ['headers' => ['X-Fbx-App-Auth' => $this->session()]]);
        if ('auth_required' === ($data['error_code'] ?? null)) {
            // Session oubliée par la box (redémarrage, inactivité) : un seul nouvel essai.
            $this->cache->delete($this->key());
            $data = $this->call('GET', $path, ['headers' => ['X-Fbx-App-Auth' => $this->session()]]);
        }
        if (true !== ($data['success'] ?? false)) {
            throw new SourceUnavailableException(\sprintf('La Freebox a refusé %s (%s)', $path, $data['error_code'] ?? 'réponse inattendue'));
        }

        return $data['result'] ?? null;
    }

    private function session(): string
    {
        if ('' === $this->appToken) {
            throw new SourceUnavailableException("Jeton d'application Freebox absent (FREEBOX_APP_TOKEN)");
        }

        return $this->cache->get($this->key(), function (ItemInterface $item): string {
            $item->expiresAfter(self::SESSION_TTL);
            $challenge = (string) ($this->call('GET', '/login/')['result']['challenge'] ?? '');
            $data = $this->call('POST', '/login/session/', ['json' => [
                'app_id' => self::APP_ID,
                'password' => hash_hmac('sha1', $challenge, $this->appToken),
            ]]);
            if (true !== ($data['success'] ?? false)) {
                throw new SourceUnavailableException(\sprintf('Connexion à la Freebox refusée (%s), vérifier FREEBOX_APP_TOKEN', $data['error_code'] ?? '?'));
            }

            return (string) $data['result']['session_token'];
        });
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $options = []): array
    {
        if ('' === $this->url) {
            throw new SourceUnavailableException("La Freebox n'est pas configurée (FREEBOX_URL)");
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->url, '/').'/api/v8'.$path, $options + ['timeout' => 3]);

            return $this->decode($response);
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Freebox injoignable : %s', $e->getMessage()), previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        // Les refus (403) ont aussi un corps JSON avec `error_code` : on le lit.
        $data = $response->toArray(false);

        return \is_array($data) ? $data : [];
    }

    private function key(): string
    {
        return 'freebox.'.hash('xxh128', $this->url.'|'.$this->appToken).'.session';
    }
}
