<?php

namespace App\Source\Jelu;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API de Jelu (0.88), avec un jeton d'API au scope reading:read. Les
 * événements de lecture couvrent tous les utilisateurs et portent chacun le
 * lecteur : un jeton suffit pour toute la famille, sans UUID à connaître.
 * Du lecteur, on ne garde que le nom de connexion (le DTO porte aussi un
 * champ password).
 */
final readonly class JeluClient
{
    /** Événements lus en une page : la famille n'en a pas davantage dans l'année */
    private const PAGE_SIZE = 200;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(JELU_API_TOKEN)%')]
        #[\SensitiveParameter]
        private string $token,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(HTTP_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * Livres en cours de lecture, tous lecteurs confondus.
     *
     * @return list<JeluReading>
     */
    public function currentlyReading(string $baseUrl): array
    {
        return array_map(
            static fn (array $e): JeluReading => self::toReading($e, $e['startDate'] ?? null),
            $this->events($baseUrl, ['eventTypes' => 'CURRENTLY_READING', 'size' => self::PAGE_SIZE]),
        );
    }

    /**
     * Livres terminés depuis cette date, les plus récents d'abord.
     *
     * @return list<JeluReading>
     */
    public function finishedSince(string $baseUrl, \DateTimeImmutable $since): array
    {
        return array_map(
            static fn (array $e): JeluReading => self::toReading($e, $e['endDate'] ?? null),
            $this->events($baseUrl, ['eventTypes' => 'FINISHED', 'endedAfter' => $since->format('Y-m-d'), 'size' => self::PAGE_SIZE, 'sort' => 'endDate,desc']),
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function toReading(array $event, ?string $date): JeluReading
    {
        $book = $event['userBook']['book'];
        $authors = array_map(static fn (array $a): string => (string) $a['name'], $book['authors'] ?? []);

        return new JeluReading(
            (string) $event['userBook']['user']['login'],
            (string) $book['id'],
            (string) $book['title'],
            [] !== $authors ? implode(', ', $authors) : null,
            ($book['image'] ?? '') ?: null,
            isset($event['userBook']['percentRead']) ? (int) $event['userBook']['percentRead'] : null,
            null === $date ? null : new \DateTimeImmutable($date),
        );
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return list<array<string, mixed>>
     */
    private function events(string $baseUrl, array $query): array
    {
        if ('' === $this->token) {
            throw new SourceUnavailableException('Jeton Jelu absent (JELU_API_TOKEN)');
        }
        $options = ['query' => $query, 'headers' => ['Authorization' => 'Bearer '.$this->token], 'timeout' => 5];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/').'/api/v1/reading-events', $options);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Jeton Jelu refusé (%d), vérifier JELU_API_TOKEN et son scope reading:read', $status));
            }

            return array_values($response->toArray()['content'] ?? []);
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Jelu injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
