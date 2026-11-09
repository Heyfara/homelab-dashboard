<?php

namespace App\Source\Dagu;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * API v1 de Dagu, l'ordonnanceur des tâches du homelab (rôle Ansible
 * service-dagu). Lecture seule : une clé d'API en rôle viewer suffit.
 */
final readonly class DaguClient
{
    /** Libellés d'état de Dagu → états de la carte */
    private const STATES = [
        'succeeded' => 'ok',
        'partially_succeeded' => 'partial',
        'failed' => 'failed',
        'aborted' => 'failed',
        'rejected' => 'failed',
        'running' => 'running',
        'queued' => 'queued',
        'waiting' => 'queued',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(DAGU_API_KEY)%')]
        #[\SensitiveParameter]
        private string $apiKey,
        /** Autorité de certification à approuver (mkcert), sinon celles du système */
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * Tâches qui portent le label, avec leurs derniers passages.
     *
     * @return list<DaguTask> dans l'ordre de Dagu (par nom)
     */
    public function tasks(string $baseUrl, string $label, int $runs): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $dags = $this->json($this->request($baseUrl.'/api/v1/dags', ['labels' => $label, 'perPage' => '100']))['dags'] ?? [];

        // Requêtes lancées d'un coup : HttpClient les mène en parallèle.
        $responses = array_map(fn (array $d): ResponseInterface => $this->request(
            \sprintf('%s/api/v1/dags/%s/dag-runs', $baseUrl, rawurlencode((string) $d['fileName'])),
            ['limit' => (string) $runs],
        ), $dags);

        return array_values(array_map(fn (array $d, ResponseInterface $r): DaguTask => new DaguTask(
            (string) $d['fileName'],
            self::description((string) ($d['dag']['description'] ?? '')),
            array_values(array_map(static fn (array $s): string => (string) $s['expression'], $d['dag']['schedule'] ?? [])),
            '' === ($d['nextRun'] ?? '') ? null : new \DateTimeImmutable($d['nextRun']),
            (bool) ($d['suspended'] ?? false),
            self::runs($this->json($r)['dagRuns'] ?? []),
        ), $dags, $responses));
    }

    /** « Texte — hôtes\nCode : lien » (rôle service-dagu) → « Texte » */
    private static function description(string $description): ?string
    {
        $text = trim(explode(' — ', explode("\n", $description)[0])[0]);

        return '' === $text ? null : $text;
    }

    /**
     * @param list<array<string, mixed>> $runs
     *
     * @return list<DaguRun>
     */
    private static function runs(array $runs): array
    {
        $result = [];
        foreach ($runs as $run) {
            if ('' === ($run['startedAt'] ?? '')) {
                continue;   // jamais parti (en attente, pas encore lancé)
            }
            $result[] = new DaguRun(
                self::STATES[$run['statusLabel'] ?? ''] ?? 'unknown',
                new \DateTimeImmutable($run['startedAt']),
                '' === ($run['finishedAt'] ?? '') ? null : new \DateTimeImmutable($run['finishedAt']),
                'scheduler' !== ($run['triggerType'] ?? 'scheduler'),
            );
        }

        return $result;
    }

    /**
     * @param array<string, string> $query
     */
    private function request(string $url, array $query): ResponseInterface
    {
        if ('' === $this->apiKey) {
            throw new SourceUnavailableException('Clé API Dagu absente (DAGU_API_KEY)');
        }
        $options = ['query' => $query, 'headers' => ['Authorization' => 'Bearer '.$this->apiKey], 'timeout' => 3];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        return $this->httpClient->request('GET', $url, $options);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        try {
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Clé API Dagu refusée (%d), vérifier DAGU_API_KEY', $status));
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Dagu injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
