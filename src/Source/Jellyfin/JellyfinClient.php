<?php

namespace App\Source\Jellyfin;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API de Jellyfin (10.11), avec une clé API dédiée au dashboard. L'adresse est
 * passée à chaque appel : elle vient du catalogue des services.
 */
final readonly class JellyfinClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire('%env(JELLYFIN_API_KEY)%')]
        #[\SensitiveParameter]
        private string $apiKey,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * Identifiant d'un utilisateur d'après son nom (casse ignorée).
     */
    public function userId(string $baseUrl, string $name): string
    {
        /** @var array<string, string> $users nom → id */
        $users = $this->cache->get('jellyfin.users.'.hash('xxh128', $baseUrl), function (ItemInterface $item) use ($baseUrl): array {
            $item->expiresAfter(3600);

            return array_column($this->get($baseUrl, '/Users'), 'Id', 'Name');
        });

        foreach ($users as $userName => $id) {
            if (0 === strcasecmp((string) $userName, $name)) {
                return $id;
            }
        }

        throw new SourceUnavailableException(\sprintf('Utilisateur Jellyfin inconnu « %s » (utilisateurs : %s)', $name, implode(', ', array_keys($users))));
    }

    /**
     * Derniers ajouts vus par cet utilisateur, comme l'écran d'accueil de Jellyfin
     * (les épisodes d'une même série sont regroupés).
     *
     * @param list<string> $types
     *
     * @return list<JellyfinItem>
     */
    public function latest(string $baseUrl, string $userId, int $limit, array $types): array
    {
        $items = $this->get($baseUrl, '/Items/Latest', [
            'userId' => $userId,
            'limit' => (string) $limit,
            'includeItemTypes' => implode(',', $types),
            'fields' => 'DateCreated',
            'enableImageTypes' => 'Primary',
            'imageTypeLimit' => '1',
        ]);

        return array_map(self::item(...), $items);
    }

    /**
     * Lectures en cours, tous utilisateurs confondus (sessions actives dans les 16 dernières minutes).
     *
     * @return list<JellyfinSession>
     */
    public function sessions(string $baseUrl): array
    {
        $sessions = [];
        foreach ($this->get($baseUrl, '/Sessions', ['activeWithinSeconds' => '960']) as $s) {
            if (!isset($s['NowPlayingItem'])) {
                continue;
            }
            $state = $s['PlayState'] ?? [];
            $sessions[] = new JellyfinSession(
                $s['Id'],
                $s['UserName'] ?? '',
                $s['Client'] ?? '',
                $s['DeviceName'] ?? '',
                self::item($s['NowPlayingItem']),
                intdiv((int) ($state['PositionTicks'] ?? 0), 10_000_000),
                intdiv((int) ($s['NowPlayingItem']['RunTimeTicks'] ?? 0), 10_000_000),
                (bool) ($state['IsPaused'] ?? false),
                'Transcode' === ($state['PlayMethod'] ?? null),
            );
        }

        return $sessions;
    }

    /**
     * @param array<string, mixed> $i
     */
    private static function item(array $i): JellyfinItem
    {
        $isEpisode = 'Episode' === $i['Type'] && isset($i['SeriesId']);

        return new JellyfinItem(
            $i['Id'],
            $i['Name'],
            $i['Type'],
            $i['ServerId'] ?? '',
            $isEpisode ? $i['SeriesId'] : $i['Id'],
            $isEpisode ? ($i['SeriesPrimaryImageTag'] ?? null) : ($i['ImageTags']['Primary'] ?? null),
            year: $i['ProductionYear'] ?? null,
            seriesName: $i['SeriesName'] ?? null,
            season: $i['ParentIndexNumber'] ?? null,
            episode: $i['IndexNumber'] ?? null,
            // Jellyfin donne 7 décimales aux secondes ; on n'en a pas besoin.
            addedAt: isset($i['DateCreated']) ? new \DateTimeImmutable(preg_replace('/\.\d+/', '', $i['DateCreated'])) : null,
        );
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function get(string $baseUrl, string $path, array $query = []): array
    {
        if ('' === $this->apiKey) {
            throw new SourceUnavailableException('Clé API Jellyfin absente (JELLYFIN_API_KEY)');
        }

        $options = [
            'query' => $query,
            'headers' => ['Authorization' => \sprintf('MediaBrowser Token="%s"', $this->apiKey)],
            'timeout' => 5,
        ];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/').$path, $options);
            if (\in_array($response->getStatusCode(), [401, 403], true)) {
                throw new SourceUnavailableException(\sprintf('Clé API Jellyfin refusée (HTTP %d), vérifier JELLYFIN_API_KEY', $response->getStatusCode()));
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Jellyfin injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
