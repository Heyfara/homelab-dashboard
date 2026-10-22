<?php

namespace App\Source\Audiobookshelf;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API d'Audiobookshelf (2.x), avec une clé d'API. Les couvertures
 * (/api/items/{id}/cover) sont publiques. Une clé admin peut lire la
 * progression de n'importe quel utilisateur.
 */
final readonly class AudiobookshelfClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(AUDIOBOOKSHELF_API_KEY)%')]
        #[\SensitiveParameter]
        private string $apiKey,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(HTTP_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * Derniers livres ajoutés, toutes bibliothèques de livres confondues.
     *
     * @return array{list<AudiobookshelfBook>, int} livres, nombre total
     */
    public function recent(string $baseUrl, int $limit): array
    {
        $books = [];
        $total = 0;
        foreach ($this->get($baseUrl, '/api/libraries')['libraries'] ?? [] as $library) {
            if ('book' !== ($library['mediaType'] ?? 'book')) {
                continue;
            }
            $page = $this->get($baseUrl, \sprintf('/api/libraries/%s/items', $library['id']), ['sort' => 'addedAt', 'desc' => '1', 'limit' => (string) $limit, 'minified' => '1']);
            $total += (int) ($page['total'] ?? 0);
            array_push($books, ...array_map(self::toBook(...), $page['results'] ?? []));
        }

        usort($books, static fn (AudiobookshelfBook $a, AudiobookshelfBook $b): int => $b->addedAt <=> $a->addedAt);

        return [\array_slice($books, 0, $limit), $total];
    }

    /**
     * Écoutes en cours (non terminées), les plus récentes d'abord.
     *
     * @param ?string $username null = le propriétaire de la clé
     *
     * @return list<AudiobookshelfProgress>
     */
    public function progress(string $baseUrl, ?string $username): array
    {
        $user = null === $username ? $this->get($baseUrl, '/api/me') : $this->get($baseUrl, '/api/users/'.$this->userId($baseUrl, $username));

        $progress = [];
        foreach ($user['mediaProgress'] ?? [] as $p) {
            if (($p['isFinished'] ?? false) || ($p['progress'] ?? 0) <= 0 || isset($p['episodeId'])) {
                continue;
            }
            $progress[] = new AudiobookshelfProgress($p['libraryItemId'], (float) $p['progress'], (int) $p['currentTime'], (int) $p['duration'], new \DateTimeImmutable('@'.intdiv((int) $p['lastUpdate'], 1000)));
        }
        usort($progress, static fn (AudiobookshelfProgress $a, AudiobookshelfProgress $b): int => $b->lastUpdate <=> $a->lastUpdate);

        return $progress;
    }

    public function book(string $baseUrl, string $id): AudiobookshelfBook
    {
        return self::toBook($this->get($baseUrl, '/api/items/'.rawurlencode($id), ['expanded' => '1']));
    }

    private function userId(string $baseUrl, string $username): string
    {
        $users = $this->get($baseUrl, '/api/users')['users'] ?? [];
        foreach ($users as $u) {
            if (0 === strcasecmp($u['username'], $username)) {
                return $u['id'];
            }
        }

        throw new SourceUnavailableException(\sprintf('Utilisateur Audiobookshelf inconnu « %s » (utilisateurs : %s)', $username, implode(', ', array_column($users, 'username'))));
    }

    /**
     * @param array<string, mixed> $i
     */
    private static function toBook(array $i): AudiobookshelfBook
    {
        $metadata = $i['media']['metadata'] ?? [];

        return new AudiobookshelfBook(
            $i['id'],
            $metadata['title'] ?? '',
            ($metadata['authorName'] ?? '') ?: null,
            (int) round($i['media']['duration'] ?? 0),
            isset($i['addedAt']) ? new \DateTimeImmutable('@'.intdiv((int) $i['addedAt'], 1000)) : null,
        );
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $baseUrl, string $path, array $query = []): array
    {
        if ('' === $this->apiKey) {
            throw new SourceUnavailableException('Clé Audiobookshelf absente (AUDIOBOOKSHELF_API_KEY)');
        }
        $options = ['query' => $query, 'headers' => ['Authorization' => 'Bearer '.$this->apiKey], 'timeout' => 5];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Clé Audiobookshelf refusée (%d), vérifier AUDIOBOOKSHELF_API_KEY', $status));
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Audiobookshelf injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
