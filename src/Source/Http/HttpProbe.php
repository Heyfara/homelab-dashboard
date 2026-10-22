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
        #[Autowire('%env(HTTP_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    public function probe(string $url): ProbeResult
    {
        $start = hrtime(true);

        try {
            $options = ['timeout' => 3, 'max_redirects' => 0];
            if (null !== $this->caFile && '' !== $this->caFile) {
                $options['cafile'] = $this->caFile;
            }
            $status = $this->httpClient->request('GET', $url, $options)->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            return new ProbeResult(false, null, self::elapsedMs($start), $e->getMessage());
        }

        return new ProbeResult($status < 500, $status, self::elapsedMs($start));
    }

    private static function elapsedMs(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1e6);
    }
}
