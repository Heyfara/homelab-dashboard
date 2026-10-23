<?php

namespace App\Source\Romm;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API de RomM (5.x), avec un jeton client (Profil → Client API Tokens).
 * Scopes utiles : roms.read, platforms.read. Les jaquettes sont servies sans
 * authentification sous /assets/romm/resources : le navigateur les lit
 * directement.
 */
final readonly class RommClient
{
    private const SCOPES = 'roms.read, platforms.read';
    private const RESOURCES = '/assets/romm/resources/';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(ROMM_API_TOKEN)%')]
        #[\SensitiveParameter]
        private string $token,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * @return array{saves: int, states: int}
     */
    public function stats(string $baseUrl): array
    {
        $stats = $this->json($baseUrl, '/api/stats');

        return ['saves' => (int) ($stats['SAVES'] ?? 0), 'states' => (int) ($stats['STATES'] ?? 0)];
    }

    /**
     * @return list<RommPlatform>
     */
    public function platforms(string $baseUrl): array
    {
        return array_map(static fn (array $p): RommPlatform => new RommPlatform(
            (int) $p['id'],
            (string) $p['slug'],
            (string) ($p['display_name'] ?? $p['custom_name'] ?? $p['name']),
            (int) ($p['rom_count'] ?? 0),
        ), array_values($this->json($baseUrl, '/api/platforms')));
    }

    /**
     * Derniers jeux ajoutés, les plus récents d'abord.
     *
     * @param list<int> $platformIds vide = toutes les plateformes
     *
     * @return list<RommGame>
     */
    public function recent(string $baseUrl, int $limit, array $platformIds = []): array
    {
        // Les index (lettres, filtres, ids, total) servent au défilement de
        // l'interface de RomM et coûtent cher : désactivés.
        $query = http_build_query([
            'order_by' => 'created_at',
            'order_dir' => 'desc',
            'limit' => $limit,
            'with_char_index' => 'false',
            'with_filter_values' => 'false',
            'with_rom_id_index' => 'false',
            'with_total' => 'false',
        ]);
        foreach ($platformIds as $id) {
            $query .= '&platform_ids='.$id;
        }

        return array_map(static fn (array $r): RommGame => new RommGame(
            (int) $r['id'],
            (string) ($r['name'] ?? null ?: $r['fs_name_no_ext'] ?? $r['fs_name']),
            (int) $r['platform_id'],
            (string) ($r['platform_display_name'] ?? ''),
            self::cover($r['path_cover_small'] ?? null),
            // Dates sans fuseau = UTC
            isset($r['created_at']) ? new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC')) : null,
        ), array_values($this->json($baseUrl, '/api/roms?'.$query)['items'] ?? []));
    }

    private static function cover(?string $path): ?string
    {
        if (null === $path || '' === $path) {
            return null;
        }

        return str_starts_with($path, '/') ? $path : self::RESOURCES.$path;
    }

    /**
     * @return array<mixed>
     */
    private function json(string $baseUrl, string $path): array
    {
        if ('' === $this->token) {
            throw new SourceUnavailableException('Jeton RomM absent (ROMM_API_TOKEN)');
        }
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->token], 'timeout' => 5];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Jeton RomM refusé (%d), vérifier ROMM_API_TOKEN et ses scopes (%s)', $status, self::SCOPES));
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('RomM injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
