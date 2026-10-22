<?php

namespace App\Source\Http;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Métadonnées d'un fichier servi en HTTP (requête HEAD, sans le télécharger). */
final readonly class RemoteFile
{
    public function __construct(
        private HttpClientInterface $httpClient,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /** Date de dernière modification (en-tête Last-Modified), null si le serveur ne la donne pas. */
    public function lastModified(string $url): ?\DateTimeImmutable
    {
        $options = ['timeout' => 3];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request('HEAD', $url, $options);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new SourceUnavailableException(\sprintf('Image introuvable (%d) : %s', $status, $url));
            }
            $header = $response->getHeaders(false)['last-modified'][0] ?? null;
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Serveur de l\'image injoignable : %s', $e->getMessage()), previous: $e);
        }

        // Date HTTP (RFC 9110) : toujours en GMT.
        $date = null === $header ? false : \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $header, new \DateTimeZone('UTC'));

        return false === $date ? null : $date;
    }
}
