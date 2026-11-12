<?php

namespace App\Source\Healthchecks;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * API v3 de Healthchecks (rôle Ansible service-healthchecks). Les tâches du
 * homelab y signalent chaque passage ; en fin de passage, le contenu du ping
 * est leur rapport (JSON de la sentinelle, de doc-fetcher…). Il faut la clé
 * complète du projet : la clé en lecture seule ne donne pas les pings.
 */
final readonly class HealthchecksClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(HEALTHCHECKS_API_KEY)%')]
        #[\SensitiveParameter]
        private string $apiKey,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * Fiche du check : état, dernier ping, passage en cours.
     */
    public function check(string $baseUrl, string $slug): HealthchecksCheck
    {
        $check = $this->get(rtrim($baseUrl, '/').'/api/v3/checks/', ['slug' => $slug])['checks'][0]
            ?? throw new SourceUnavailableException(\sprintf('Check « %s » absent de Healthchecks', $slug));

        return new HealthchecksCheck(
            (string) ($check['status'] ?? 'new'),
            isset($check['last_ping']) ? new \DateTimeImmutable($check['last_ping']) : null,
            (bool) ($check['started'] ?? false),
        );
    }

    /**
     * Rapports des derniers passages terminés (succès ou échec) du check.
     *
     * @return list<string> le plus récent d'abord ; vide si le check n'a pas encore de rapport
     */
    public function reports(string $baseUrl, string $slug, int $limit): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $uuid = $this->get($baseUrl.'/api/v3/checks/', ['slug' => $slug])['checks'][0]['uuid'] ?? null;
        if (null === $uuid) {
            throw new SourceUnavailableException(\sprintf('Check « %s » absent de Healthchecks', $slug));
        }

        $ends = array_filter(
            $this->get(\sprintf('%s/api/v3/checks/%s/pings/', $baseUrl, $uuid))['pings'] ?? [],
            static fn (array $p): bool => \in_array($p['type'] ?? null, ['success', 'fail'], true) && null !== ($p['body_url'] ?? null),
        );
        // Requêtes lancées d'un coup : HttpClient les mène en parallèle.
        $responses = array_map(fn (array $p): ResponseInterface => $this->request((string) $p['body_url']), \array_slice(array_values($ends), 0, $limit));

        return array_map(fn (ResponseInterface $r): string => $this->content($r), $responses);
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $url, array $query = []): array
    {
        return json_decode($this->content($this->request($url, $query)), true) ?: [];
    }

    /**
     * @param array<string, string> $query
     */
    private function request(string $url, array $query = []): ResponseInterface
    {
        if ('' === $this->apiKey) {
            throw new SourceUnavailableException('Clé API Healthchecks absente (HEALTHCHECKS_API_KEY)');
        }
        $options = ['query' => $query, 'headers' => ['X-Api-Key' => $this->apiKey], 'timeout' => 5];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        return $this->httpClient->request('GET', $url, $options);
    }

    private function content(ResponseInterface $response): string
    {
        try {
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Clé API Healthchecks refusée (%d), vérifier HEALTHCHECKS_API_KEY', $status));
            }

            return $response->getContent();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Healthchecks injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
