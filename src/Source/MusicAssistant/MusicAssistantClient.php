<?php

namespace App\Source\MusicAssistant;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API de Music Assistant (2.10, POST /api), avec un jeton longue durée.
 * Les images passent par /imageproxy/<proxy_id>, public : on ne garde jamais
 * le chemin d'origine, qui peut porter la clé d'un fournisseur (Jellyfin).
 */
final readonly class MusicAssistantClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(MUSIC_ASSISTANT_TOKEN)%')]
        #[\SensitiveParameter]
        private string $token,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(HTTP_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * Enceintes joignables et visibles dans Music Assistant, plus les lecteurs
     * masqués (navigateur) quand ils jouent ou sont en pause.
     *
     * @return list<Speaker>
     */
    public function speakers(string $baseUrl): array
    {
        $speakers = [];
        foreach ($this->command($baseUrl, 'players/all') as $p) {
            $state = (string) ($p['playback_state'] ?? $p['state'] ?? 'idle');
            $active = \in_array($state, ['playing', 'paused'], true);
            // Lecteurs masqués (navigateur, interface web de Music Assistant) : seulement quand ils jouent.
            if (!($p['available'] ?? false) || !($p['enabled'] ?? true) || (($p['hide_in_ui'] ?? false) && !$active)) {
                continue;
            }
            $media = $active ? ($p['current_media'] ?? null) : null;

            $speakers[] = new Speaker((string) $p['player_id'], (string) ($p['display_name'] ?? $p['name']), $state, null === $media ? null : new NowPlaying(
                (string) ($media['title'] ?? ''),
                ($media['artist'] ?? '') ?: null,
                ($media['album'] ?? '') ?: null,
                self::proxiedPath($media['image_url'] ?? null),
                isset($media['duration']) ? (int) $media['duration'] : null,
                // Position dans le titre : celle du média. Celle du lecteur se cumule sur
                // toute la file (constaté sur le lecteur web, 2.10.6) et dépasse la durée.
                (float) ($media['elapsed_time'] ?? $p['elapsed_time'] ?? 0),
                (float) ($media['elapsed_time_last_updated'] ?? $p['elapsed_time_last_updated'] ?? 0),
                self::hex($media['palette']['accent'] ?? null),
            ));
        }

        return $speakers;
    }

    /**
     * Dernières écoutes (titres, albums, artistes…), les plus récentes d'abord.
     *
     * @return list<MusicItem>
     */
    public function recentlyPlayed(string $baseUrl, int $limit): array
    {
        return array_map(static fn (array $i): MusicItem => new MusicItem(
            (string) $i['name'],
            (string) ($i['media_type'] ?? 'track'),
            isset($i['image']['proxy_id']) ? \sprintf('/imageproxy/%s?size=256&fmt=jpg', rawurlencode((string) $i['image']['proxy_id'])) : null,
        ), $this->command($baseUrl, 'music/recently_played_items', ['limit' => $limit]));
    }

    /** Garde le chemin /imageproxy/… d'une URL d'image, rien d'autre. */
    private static function proxiedPath(?string $url): ?string
    {
        $path = parse_url((string) $url, \PHP_URL_PATH);
        if (!\is_string($path) || !str_starts_with($path, '/imageproxy/')) {
            return null;
        }
        $query = parse_url((string) $url, \PHP_URL_QUERY);

        return $path.(\is_string($query) ? '?'.$query : '');
    }

    /**
     * Couleur de la pochette, si elle se voit sur fond clair comme sur fond
     * sombre ; trop sombre ou trop claire (luminance relative WCAG), null.
     *
     * @param list<int>|null $rgb
     */
    private static function hex(?array $rgb): ?string
    {
        if (null === $rgb || 3 !== \count($rgb)) {
            return null;
        }
        $linear = array_map(static fn (int $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $rgb);
        $luminance = 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];

        return $luminance < 0.05 || $luminance > 0.8 ? null : \sprintf('#%02x%02x%02x', ...$rgb);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return list<array<string, mixed>>
     */
    private function command(string $baseUrl, string $command, array $args = []): array
    {
        if ('' === $this->token) {
            throw new SourceUnavailableException('Jeton Music Assistant absent (MUSIC_ASSISTANT_TOKEN)');
        }
        $options = [
            'headers' => ['Authorization' => 'Bearer '.$this->token],
            'json' => ['message_id' => $command, 'command' => $command] + ([] === $args ? [] : ['args' => $args]),
            'timeout' => 5,
        ];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($baseUrl, '/').'/api', $options);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Jeton Music Assistant refusé (%d), vérifier MUSIC_ASSISTANT_TOKEN', $status));
            }

            return array_values($response->toArray());
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Music Assistant injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
