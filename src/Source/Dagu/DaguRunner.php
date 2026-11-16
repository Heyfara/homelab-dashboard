<?php

namespace App\Source\Dagu;

use App\Card\CardActionRefusedException;
use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Lancer et arrêter une tâche Dagu (carte urssaf). Clé d'API à part, en rôle
 * operator : DaguClient garde sa clé en lecture seule. Une clé operator peut
 * lancer n'importe quelle tâche : le dashboard ne lance que celles nommées
 * dans le YAML.
 */
final readonly class DaguRunner
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(DAGU_OPERATOR_API_KEY)%')]
        #[\SensitiveParameter]
        private string $apiKey,
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * Lance un passage ; refusé si la tâche tourne déjà.
     *
     * @param array<string, string> $params
     *
     * @return string identifiant du passage
     */
    public function start(string $baseUrl, string $dag, array $params): string
    {
        $url = \sprintf('%s/api/v1/dags/%s/start', rtrim($baseUrl, '/'), rawurlencode($dag));
        $response = $this->post($url, ['params' => json_encode((object) $params, \JSON_THROW_ON_ERROR), 'singleton' => true]);
        if (409 === $this->status($response)) {
            throw new CardActionRefusedException(\sprintf('La tâche %s tourne déjà.', $dag));
        }

        return (string) ($this->decode($response)['dagRunId'] ?? throw new SourceUnavailableException('Dagu n\'a pas renvoyé d\'identifiant de passage'));
    }

    public function stop(string $baseUrl, string $dag, string $runId): void
    {
        $url = \sprintf('%s/api/v1/dag-runs/%s/%s/stop', rtrim($baseUrl, '/'), rawurlencode($dag), rawurlencode($runId));
        $this->decode($this->post($url, null), allowEmpty: true);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function post(string $url, ?array $body): ResponseInterface
    {
        if ('' === $this->apiKey) {
            throw new SourceUnavailableException('Clé API Dagu de lancement absente (DAGU_OPERATOR_API_KEY)');
        }
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->apiKey], 'timeout' => 5];
        if (null !== $body) {
            $options['json'] = $body;
        }
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        return $this->httpClient->request('POST', $url, $options);
    }

    private function status(ResponseInterface $response): int
    {
        try {
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Dagu injoignable : %s', $e->getMessage()), previous: $e);
        }
        if (401 === $status || 403 === $status) {
            throw new SourceUnavailableException(\sprintf('Clé API Dagu refusée (%d), vérifier DAGU_OPERATOR_API_KEY (rôle operator)', $status));
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, bool $allowEmpty = false): array
    {
        $this->status($response);
        try {
            if ($allowEmpty && '' === $response->getContent()) {
                return [];
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Dagu injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
