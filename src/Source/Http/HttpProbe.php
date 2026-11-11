<?php

namespace App\Source\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Le service répond-il ? Toute réponse HTTP sous 500 compte comme « oui » :
 * une page de connexion (401) ou une redirection prouvent que le service tourne.
 */
final readonly class HttpProbe
{
    public function __construct(
        private HttpClientInterface $httpClient,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    public function probe(string $url): ProbeResult
    {
        $start = hrtime(true);

        try {
            $status = $this->httpClient->request('GET', $url, $this->options())->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            return new ProbeResult(false, null, self::elapsedMs($start), $e->getMessage());
        }

        return new ProbeResult($status < 500, $status, self::elapsedMs($start));
    }

    /**
     * Plusieurs sondes en parallèle : toutes les requêtes partent avant la
     * lecture de la première réponse (un lanceur de 12 services n'attend pas
     * 12 fois de suite). Les clés de $urls sont gardées.
     *
     * @template K of array-key
     *
     * @param array<K, string> $urls
     *
     * @return array<K, ProbeResult>
     */
    public function probeAll(array $urls): array
    {
        $start = hrtime(true);
        $responses = [];
        $results = [];
        foreach ($urls as $key => $url) {
            try {
                $responses[$key] = $this->httpClient->request('GET', $url, $this->options());
            } catch (TransportExceptionInterface $e) {
                $results[$key] = new ProbeResult(false, null, self::elapsedMs($start), $e->getMessage());
            }
        }

        foreach ($urls as $key => $url) {
            if (isset($results[$key])) {
                continue;
            }
            try {
                $status = $responses[$key]->getStatusCode();
                $results[$key] = new ProbeResult($status < 500, $status, self::elapsedMs($start));
            } catch (TransportExceptionInterface $e) {
                $results[$key] = new ProbeResult(false, null, self::elapsedMs($start), $e->getMessage());
            }
        }

        // Dans l’ordre de $urls (un échec à l’envoi a pu être rangé avant les autres).
        return array_replace(array_intersect_key($urls, $results), $results);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $options = ['timeout' => 3, 'max_redirects' => 0];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        return $options;
    }

    private static function elapsedMs(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1e6);
    }
}
