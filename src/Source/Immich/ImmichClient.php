<?php

namespace App\Source\Immich;

use App\Card\Image\CardImage;
use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * API d'Immich (3.x), avec une clé d'API : les photos sont celles du
 * propriétaire de la clé. Permissions utiles : asset.read, asset.view,
 * asset.statistics, memory.read. Les miniatures exigent la clé : le
 * dashboard les relaie (ImageSourceInterface).
 */
final readonly class ImmichClient
{
    private const PERMISSIONS = 'asset.read, asset.view, asset.statistics, memory.read';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    public const SIZES = ['thumbnail', 'preview'];
    /** Une miniature peut prendre plusieurs secondes ; gardée 24 h ensuite */
    private const IMAGE_TIMEOUT = 15;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(IMMICH_API_KEY)%')]
        #[\SensitiveParameter]
        private string $apiKey,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(HTTP_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * @return array{images: int, videos: int}
     */
    public function statistics(string $baseUrl): array
    {
        $stats = $this->json($baseUrl, 'GET', '/api/assets/statistics');

        return ['images' => (int) ($stats['images'] ?? 0), 'videos' => (int) ($stats['videos'] ?? 0)];
    }

    /**
     * Photos et vidéos les plus récentes de la frise (ni archivées, ni verrouillées).
     *
     * @return list<ImmichAsset>
     */
    public function recent(string $baseUrl, int $limit): array
    {
        $page = $this->json($baseUrl, 'POST', '/api/search/metadata', ['json' => [
            'size' => $limit,
            'orderBy' => ['field' => 'fileCreatedAt', 'direction' => 'desc'],
            'filter' => ['visibility' => ['eq' => 'timeline'], 'type' => ['in' => ['IMAGE', 'VIDEO']]],
        ]]);

        return array_map(self::toAsset(...), array_values($page['assets']['items'] ?? []));
    }

    /**
     * Souvenirs « ce jour-là » affichés par Immich à cette date, année la plus récente d'abord.
     *
     * @return list<ImmichMemory>
     */
    public function memories(string $baseUrl, \DateTimeImmutable $day): array
    {
        $memories = [];
        foreach ($this->json($baseUrl, 'GET', '/api/memories', ['query' => ['for' => $day->format('Y-m-d'), 'type' => 'on_this_day']]) as $m) {
            $assets = array_map(self::toAsset(...), array_values($m['assets'] ?? []));
            if ([] !== $assets) {
                $memories[] = new ImmichMemory($m['id'], (int) ($m['data']['year'] ?? 0), $assets);
            }
        }
        usort($memories, static fn (ImmichMemory $a, ImmichMemory $b): int => $b->year <=> $a->year);

        return $memories;
    }

    /**
     * @param 'thumbnail'|'preview' $size
     */
    public function thumbnail(string $baseUrl, string $assetId, string $size): CardImage
    {
        if (!preg_match(self::UUID, $assetId) || !\in_array($size, self::SIZES, true)) {
            throw new \InvalidArgumentException(\sprintf('Miniature Immich invalide : %s (%s)', $assetId, $size));
        }
        $response = $this->request($baseUrl, 'GET', \sprintf('/api/assets/%s/thumbnail', $assetId), ['query' => ['size' => $size], 'timeout' => self::IMAGE_TIMEOUT]);

        try {
            return new CardImage($response->getContent(), $response->getHeaders()['content-type'][0] ?? '');
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Immich injoignable : %s', $e->getMessage()), previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function toAsset(array $a): ImmichAsset
    {
        // localDateTime est l'heure de l'appareil, marquée « Z » à tort : on garde l'heure telle quelle.
        $local = $a['localDateTime'] ?? $a['fileCreatedAt'] ?? null;

        return new ImmichAsset($a['id'], 'VIDEO' === ($a['type'] ?? null), null === $local ? null : new \DateTimeImmutable(substr((string) $local, 0, 19)));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function json(string $baseUrl, string $method, string $path, array $options = []): array
    {
        $response = $this->request($baseUrl, $method, $path, $options);

        try {
            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Immich injoignable : %s', $e->getMessage()), previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $baseUrl, string $method, string $path, array $options = []): ResponseInterface
    {
        if ('' === $this->apiKey) {
            throw new SourceUnavailableException('Clé Immich absente (IMMICH_API_KEY)');
        }
        $options += ['headers' => ['x-api-key' => $this->apiKey], 'timeout' => 5];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request($method, rtrim($baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Clé Immich refusée (%d), vérifier IMMICH_API_KEY et ses permissions (%s)', $status, self::PERMISSIONS));
            }

            return $response;
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Immich injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
