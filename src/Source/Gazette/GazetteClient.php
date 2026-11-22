<?php

namespace App\Source\Gazette;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API de la gazette familiale (dépôt Heyfara/gazette). Sans authentification :
 * l'appli n'est joignable que sur le réseau local.
 */
final readonly class GazetteClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        /** Autorité de certification à approuver (mkcert), sinon celles du système */
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * @return list<GazetteIssue> dans l'ordre de l'API : le plus récent d'abord
     */
    public function issues(string $baseUrl): array
    {
        $options = ['headers' => ['Accept' => 'application/json'], 'timeout' => 5];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $data = $this->httpClient->request('GET', rtrim($baseUrl, '/').'/api/issues', $options)->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Gazette injoignable : %s', $e->getMessage()), previous: $e);
        }

        return array_values(array_map(static fn (array $i): GazetteIssue => new GazetteIssue(
            (string) $i['id'],
            (int) $i['number'],
            (string) ($i['title'] ?? ''),
            (string) ($i['month'] ?? ''),
            (string) ($i['status'] ?? ''),
            (int) ($i['notes'] ?? 0),
            isset($i['sentAt']) ? new \DateTimeImmutable((string) $i['sentAt']) : null,
        ), $data));
    }
}
