<?php

namespace App\Source\Backblaze;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * API native de Backblaze B2 (v3), avec une clé d'application en lecture seule.
 * B2 n'a ni taille de bucket ni facturation dans son API : la taille se
 * reconstitue en listant les versions de fichiers (3 appels pour ~22 000 fichiers,
 * appels de classe C, 2 500 gratuits par jour).
 */
final readonly class B2Client
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(B2_KEY_ID)%')]
        private string $keyId,
        #[Autowire('%env(B2_APPLICATION_KEY)%')]
        #[\SensitiveParameter]
        private string $applicationKey,
        private string $authUrl = 'https://api.backblazeb2.com',
    ) {
    }

    /**
     * Buckets visibles par la clé : le seul autorisé si elle est limitée à un bucket.
     *
     * @return list<B2Bucket>
     */
    public function buckets(): array
    {
        $auth = $this->authorize();
        $query = ['accountId' => $auth['accountId']];
        if (null !== ($auth['bucketName'] ?? null)) {
            // Clé limitée à un bucket : la liste complète lui est refusée (401).
            $query['bucketName'] = $auth['bucketName'];
        }

        $buckets = $this->get($auth, 'b2_list_buckets', $query)['buckets'] ?? [];

        return array_map(static fn (array $b): B2Bucket => new B2Bucket($b['bucketId'], $b['bucketName'], $b['bucketType'] ?? ''), $buckets);
    }

    public function usage(B2Bucket $bucket): B2Usage
    {
        $auth = $this->authorize();
        $bytes = $files = $last = 0;
        $start = [];

        do {
            $page = $this->get($auth, 'b2_list_file_versions', ['bucketId' => $bucket->id, 'maxFileCount' => '10000', ...$start]);
            foreach ($page['files'] ?? [] as $file) {
                // « hide » (fichier masqué) et « start » (envoi en cours) ne sont pas des données stockées.
                if ('upload' !== ($file['action'] ?? 'upload')) {
                    continue;
                }
                $bytes += (int) $file['contentLength'];
                ++$files;
                $last = max($last, (int) $file['uploadTimestamp']);
            }
            $start = null !== ($page['nextFileName'] ?? null) ? ['startFileName' => $page['nextFileName'], 'startFileId' => $page['nextFileId']] : [];
        } while ([] !== $start);

        return new B2Usage($bytes, $files, $last > 0 ? new \DateTimeImmutable('@'.intdiv($last, 1000)) : null);
    }

    /**
     * @return array{accountId: string, token: string, apiUrl: string, bucketName: ?string}
     */
    private function authorize(): array
    {
        if ('' === $this->keyId || '' === $this->applicationKey) {
            throw new SourceUnavailableException('Clé Backblaze absente (B2_KEY_ID, B2_APPLICATION_KEY)');
        }

        $data = $this->decode($this->request(rtrim($this->authUrl, '/').'/b2api/v3/b2_authorize_account', [
            'headers' => ['Authorization' => 'Basic '.base64_encode($this->keyId.':'.$this->applicationKey)],
        ]));
        $storage = $data['apiInfo']['storageApi'] ?? [];

        return [
            'accountId' => $data['accountId'],
            'token' => $data['authorizationToken'],
            'apiUrl' => $storage['apiUrl'],
            'bucketName' => $storage['bucketName'] ?? null,
        ];
    }

    /**
     * @param array{token: string, apiUrl: string} $auth
     * @param array<string, string>                $query
     *
     * @return array<string, mixed>
     */
    private function get(array $auth, string $endpoint, array $query): array
    {
        return $this->decode($this->request(rtrim($auth['apiUrl'], '/').'/b2api/v3/'.$endpoint, [
            'query' => $query,
            'headers' => ['Authorization' => $auth['token']],
        ]));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $url, array $options): ResponseInterface
    {
        try {
            $response = $this->httpClient->request('GET', $url, $options + ['timeout' => 10]);
            $response->getStatusCode();

            return $response;
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Backblaze injoignable : %s', $e->getMessage()), previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        if (401 === $status) {
            throw new SourceUnavailableException('Clé Backblaze refusée (401), vérifier B2_KEY_ID et B2_APPLICATION_KEY');
        }

        try {
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Backblaze a répondu %d', $status), previous: $e);
        }
        if (200 !== $status) {
            throw new SourceUnavailableException(\sprintf('Backblaze a répondu %d : %s', $status, $data['message'] ?? $data['code'] ?? ''));
        }

        return $data;
    }
}
