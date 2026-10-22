<?php

namespace App\Source\CalibreWeb;

use App\Card\Image\CardImage;
use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Catalogue OPDS de Calibre-Web (et Calibre-Web Automated), en Basic auth.
 * Calibre-Web n'a pas d'API JSON : le flux OPDS est l'interface stable. Les
 * couvertures exigent aussi l'authentification : le dashboard les relaie.
 */
final readonly class CalibreWebClient
{
    /** Garde-fou : pages OPDS lues au plus pour un appel */
    private const MAX_PAGES = 10;
    /** Une couverture peut prendre plusieurs secondes (générée à la volée) ; gardée 24 h ensuite */
    private const IMAGE_TIMEOUT = 15;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(CWA_USERNAME)%')]
        private string $username,
        #[Autowire('%env(CWA_PASSWORD)%')]
        #[\SensitiveParameter]
        private string $password,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(HTTP_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * Derniers livres ajoutés, les plus récents d'abord.
     *
     * @return list<CalibreWebBook>
     */
    public function recent(string $baseUrl, int $limit): array
    {
        // Calibre-Web n'annonce pas de page suivante : on avance par offset
        // jusqu'à une page plus courte que la première.
        $books = [];
        $read = 0;
        $pageSize = null;
        for ($page = 0; $page < self::MAX_PAGES && \count($books) < $limit; ++$page) {
            $entries = $this->feed($baseUrl, 0 === $page ? '/opds/new' : '/opds/new?offset='.$read)->entry;
            $read += \count($entries);
            foreach ($entries as $entry) {
                $book = self::toBook($entry);
                if (null !== $book) {
                    $books[] = $book;
                }
            }
            $pageSize ??= \count($entries);
            if (0 === \count($entries) || \count($entries) < $pageSize) {
                break;
            }
        }

        return \array_slice($books, 0, $limit);
    }

    public function cover(string $baseUrl, int $bookId): CardImage
    {
        $response = $this->request($baseUrl, \sprintf('/opds/cover/%d', $bookId), self::IMAGE_TIMEOUT);

        try {
            return new CardImage($response->getContent(), $response->getHeaders()['content-type'][0] ?? '');
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Calibre-Web injoignable : %s', $e->getMessage()), previous: $e);
        }
    }

    private static function toBook(\SimpleXMLElement $entry): ?CalibreWebBook
    {
        $id = null;
        $hasCover = false;
        foreach ($entry->link as $link) {
            if (!preg_match('#/opds/(cover|download)/(\d+)#', (string) $link['href'], $m)) {
                continue;
            }
            $id ??= (int) $m[2];
            $hasCover = $hasCover || 'cover' === $m[1];
        }
        if (null === $id) {
            return null;
        }
        $authors = [];
        foreach ($entry->author as $author) {
            $authors[] = trim((string) $author->name);
        }
        $updated = (string) $entry->updated;

        return new CalibreWebBook($id, trim((string) $entry->title), [] !== $authors ? implode(', ', $authors) : null, $hasCover, '' !== $updated ? new \DateTimeImmutable($updated) : null);
    }

    private function feed(string $baseUrl, string $path): \SimpleXMLElement
    {
        $response = $this->request($baseUrl, $path);

        try {
            $body = $response->getContent();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Calibre-Web injoignable : %s', $e->getMessage()), previous: $e);
        }
        $feed = @simplexml_load_string($body, options: \LIBXML_NONET);
        if (false === $feed || 'feed' !== $feed->getName()) {
            throw new SourceUnavailableException(\sprintf('La réponse de %s n\'est pas un flux OPDS (page de connexion ?)', $path));
        }

        return $feed;
    }

    private function request(string $baseUrl, string $path, int $timeout = 5): ResponseInterface
    {
        if ('' === $this->username) {
            throw new SourceUnavailableException('Identifiants Calibre-Web absents (CWA_USERNAME, CWA_PASSWORD)');
        }
        $options = ['auth_basic' => [$this->username, $this->password], 'timeout' => $timeout];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Identifiants Calibre-Web refusés (%d), vérifier CWA_USERNAME et CWA_PASSWORD', $status));
            }

            return $response;
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Calibre-Web injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
