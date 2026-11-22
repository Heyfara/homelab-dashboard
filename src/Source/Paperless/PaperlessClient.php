<?php

namespace App\Source\Paperless;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API REST de Paperless-ngx (2.x, 3.x), avec le jeton d'un utilisateur en
 * lecture seule : uniquement des GET. Paperless ne renvoie que les documents
 * que cet utilisateur a le droit de voir.
 */
final readonly class PaperlessClient
{
    /** 100 par page, 10 pages au plus : largement de quoi lister les factures en attente. */
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 10;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(PAPERLESS_API_TOKEN)%')]
        #[\SensitiveParameter]
        private string $token,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * @return list<PaperlessCustomField>
     */
    public function customFields(string $baseUrl): array
    {
        return array_map(static fn (array $f): PaperlessCustomField => new PaperlessCustomField(
            (int) $f['id'],
            (string) $f['name'],
            (string) ($f['data_type'] ?? ''),
            ($f['extra_data']['default_currency'] ?? null) ?: null,
        ), $this->all($baseUrl, '/api/custom_fields/', []));
    }

    /**
     * Documents d'un type, désigné par son nom (sans tenir compte de la casse).
     *
     * @param array<mixed>|null $customFieldQuery filtre sur les champs personnalisés (custom_field_query de l'API)
     *
     * @return list<PaperlessDocument>
     */
    public function documents(string $baseUrl, string $documentType, ?array $customFieldQuery): array
    {
        $query = [
            'document_type__name__iexact' => $documentType,
            'fields' => 'id,title,created,correspondent,custom_fields',
        ];
        if (null !== $customFieldQuery) {
            $query['custom_field_query'] = json_encode($customFieldQuery, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        }

        return array_map(static function (array $d): PaperlessDocument {
            $fields = [];
            foreach ($d['custom_fields'] ?? [] as $f) {
                $fields[(int) $f['field']] = $f['value'] ?? null;
            }

            return new PaperlessDocument(
                (int) $d['id'],
                (string) ($d['title'] ?? ''),
                isset($d['created']) ? substr((string) $d['created'], 0, 10) : null,
                isset($d['correspondent']) ? (int) $d['correspondent'] : null,
                $fields,
            );
        }, $this->all($baseUrl, '/api/documents/', $query));
    }

    /**
     * @return array<int, string> nom par id
     */
    public function correspondents(string $baseUrl): array
    {
        $names = [];
        foreach ($this->all($baseUrl, '/api/correspondents/', []) as $c) {
            $names[(int) $c['id']] = (string) $c['name'];
        }

        return $names;
    }

    /**
     * Toutes les pages d'une liste. On suit le numéro de page, pas l'adresse
     * `next`, qui porte le nom public de Paperless plutôt que l'adresse jointe.
     *
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function all(string $baseUrl, string $path, array $query): array
    {
        $results = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $data = $this->get($baseUrl, $path, [...$query, 'page_size' => (string) self::PAGE_SIZE, 'page' => (string) $page]);
            array_push($results, ...array_values($data['results'] ?? []));
            if (null === ($data['next'] ?? null)) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $baseUrl, string $path, array $query): array
    {
        if ('' === $this->token) {
            throw new SourceUnavailableException('Jeton Paperless absent (PAPERLESS_API_TOKEN)');
        }
        $options = [
            'query' => $query,
            'headers' => ['Authorization' => 'Token '.$this->token, 'Accept' => 'application/json; version=9'],
            'timeout' => 5,
        ];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Jeton Paperless refusé (%d), vérifier PAPERLESS_API_TOKEN', $status));
            }
            // Filtre ou version d'API refusés : Paperless dit pourquoi dans le corps.
            if ($status >= 400 && $status < 500) {
                throw new SourceUnavailableException(\sprintf('Paperless refuse la requête (%d) : %s', $status, mb_substr(trim($response->getContent(false)), 0, 200)));
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Paperless injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
