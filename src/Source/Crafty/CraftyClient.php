<?php

namespace App\Source\Crafty;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API v2 de Crafty Controller (4.11), avec une clé d'API. Une clé sans accès
 * complet ne voit que les serveurs des rôles de son utilisateur. De la liste
 * des serveurs, on ne garde que l'identité : elle porte aussi les chemins et
 * la commande de lancement.
 */
final readonly class CraftyClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(CRAFTY_API_TOKEN)%')]
        #[\SensitiveParameter]
        private string $token,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(HTTP_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * Serveurs visibles par la clé, avec leur dernier relevé (Crafty les ping
     * lui-même toutes les quelques secondes).
     *
     * @return list<CraftyServer>
     */
    public function servers(string $baseUrl): array
    {
        $servers = [];
        foreach ($this->get($baseUrl, '/api/v2/servers') as $s) {
            $id = (string) $s['server_id'];
            $stats = $this->get($baseUrl, \sprintf('/api/v2/servers/%s/stats', rawurlencode($id)));
            $servers[] = new CraftyServer(
                $id,
                (string) $s['server_name'],
                (int) ($s['server_port'] ?? $stats['server_port'] ?? 0),
                self::state($stats),
                (int) ($stats['online'] ?? 0),
                (int) ($stats['max'] ?? 0),
                self::players($stats['players'] ?? null),
                self::value($stats['version'] ?? null),
                null === ($motd = self::value($stats['desc'] ?? null)) ? null : self::plain($motd),
                null === ($icon = self::value($stats['icon'] ?? null)) ? null : 'data:image/png;base64,'.preg_replace('/\s+/', '', $icon),
                self::value($stats['world_size'] ?? null),
                null === ($started = self::value($stats['started'] ?? null)) ? null : new \DateTimeImmutable($started),
            );
        }

        return $servers;
    }

    /**
     * @param array<string, mixed> $stats
     */
    private static function state(array $stats): string
    {
        return match (true) {
            (bool) ($stats['crashed'] ?? false) => 'crashed',
            (bool) ($stats['waiting_start'] ?? false), (bool) ($stats['updating'] ?? false), (bool) ($stats['importing'] ?? false) => 'starting',
            (bool) ($stats['running'] ?? false) => 'online',
            default => 'stopped',
        };
    }

    /** Crafty écrit « False » (texte) dans les champs du ping quand le serveur ne répond pas. */
    private static function value(mixed $v): ?string
    {
        return null === $v || false === $v || '' === $v || 'False' === $v ? null : (string) $v;
    }

    /**
     * Liste de joueurs stockée comme texte : liste Python (« ['Steve', 'Alex'] »)
     * ou JSON selon les versions, « False » serveur arrêté.
     *
     * @return list<string>
     */
    private static function players(mixed $raw): array
    {
        if (\is_array($raw)) {
            return array_values(array_map('strval', $raw));
        }
        preg_match_all('/([\'"])((?:(?!\1).)*)\1/u', (string) $raw, $m);

        return $m[2];
    }

    /** Retire les codes de mise en forme Minecraft (§a, §l…). */
    private static function plain(string $motd): string
    {
        return trim((string) preg_replace('/§./u', '', $motd));
    }

    /**
     * @return array<mixed>
     */
    private function get(string $baseUrl, string $path): array
    {
        if ('' === $this->token) {
            throw new SourceUnavailableException('Clé Crafty absente (CRAFTY_API_TOKEN)');
        }
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->token], 'timeout' => 5];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Clé Crafty refusée (%d), vérifier CRAFTY_API_TOKEN et l\'accès de son utilisateur aux serveurs', $status));
            }

            return (array) ($response->toArray()['data'] ?? []);
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Crafty injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
