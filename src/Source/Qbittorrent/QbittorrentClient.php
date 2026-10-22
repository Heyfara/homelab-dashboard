<?php

namespace App\Source\Qbittorrent;

use App\Source\SourceUnavailableException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * API WebUI de qBittorrent (v2). qBittorrent n'a qu'un compte, avec tous les
 * droits : le dashboard ne fait que lire.
 *
 * qBittorrent bannit une adresse après 5 connexions refusées : un refus est
 * retenu 10 minutes, pendant lesquelles on ne retente pas.
 */
final readonly class QbittorrentClient
{
    private const SESSION_TTL = 1800;
    private const REFUSED_TTL = 600;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $cache,
        #[Autowire('%env(QBITTORRENT_USERNAME)%')]
        private string $username,
        #[Autowire('%env(QBITTORRENT_PASSWORD)%')]
        #[\SensitiveParameter]
        private string $password,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * Totaux, débits et liste des torrents, en un seul appel (sync/maindata).
     */
    public function snapshot(string $baseUrl): QbittorrentSnapshot
    {
        $data = $this->authenticated($baseUrl, '/api/v2/sync/maindata');
        $state = $data['server_state'] ?? [];

        $torrents = [];
        foreach ($data['torrents'] ?? [] as $hash => $t) {
            $torrents[] = new QbittorrentTorrent(
                (string) $hash,
                $t['name'],
                (int) $t['size'],
                (float) $t['progress'],
                $t['state'],
                (int) $t['added_on'],
                ($t['completion_on'] ?? -1) > 0 ? (int) $t['completion_on'] : null,
            );
        }

        return new QbittorrentSnapshot(
            (int) ($state['alltime_dl'] ?? 0),
            (int) ($state['alltime_ul'] ?? 0),
            (int) ($state['dl_info_speed'] ?? 0),
            (int) ($state['up_info_speed'] ?? 0),
            isset($state['global_ratio']) ? (float) $state['global_ratio'] : null,
            $torrents,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function authenticated(string $baseUrl, string $path): array
    {
        $response = $this->request('GET', $baseUrl.$path, ['headers' => ['Cookie' => $this->session($baseUrl)]]);
        if (\in_array($response->getStatusCode(), [401, 403], true)) {
            // Session expirée (qBittorrent redémarré, délai dépassé) : une seule reconnexion.
            $this->cache->deleteItem($this->key($baseUrl, 'session'));
            $response = $this->request('GET', $baseUrl.$path, ['headers' => ['Cookie' => $this->session($baseUrl)]]);
        }

        try {
            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('qBittorrent a répondu %d', $response->getStatusCode()), previous: $e);
        }
    }

    private function session(string $baseUrl): string
    {
        if ('' === $this->username || '' === $this->password) {
            throw new SourceUnavailableException('Identifiants qBittorrent absents (QBITTORRENT_USERNAME, QBITTORRENT_PASSWORD)');
        }

        $refused = $this->cache->getItem($this->key($baseUrl, 'refused'));
        if ($refused->isHit()) {
            throw new SourceUnavailableException((string) $refused->get());
        }

        $session = $this->cache->getItem($this->key($baseUrl, 'session'));
        if ($session->isHit()) {
            return (string) $session->get();
        }

        $response = $this->request('POST', $baseUrl.'/api/v2/auth/login', [
            'body' => ['username' => $this->username, 'password' => $this->password],
        ]);
        $cookie = $response->getHeaders(false)['set-cookie'][0] ?? null;

        // Connexion réussie : « 200 Ok. » jusqu'à qBittorrent 5.1, « 204 » sans corps ensuite.
        $status = $response->getStatusCode();
        $accepted = 204 === $status || (200 === $status && 'Ok.' === trim($response->getContent(false)));

        if (!$accepted || null === $cookie) {
            $message = \sprintf(
                'Connexion à qBittorrent refusée pour %s (HTTP %d) ; nouvel essai dans 10 min, pour ne pas faire bannir l\'adresse. Vérifier QBITTORRENT_USERNAME, QBITTORRENT_PASSWORD, et que l\'adresse est dans WebUI\\ServerDomains',
                $this->username,
                $status,
            );
            $this->cache->save($refused->set($message)->expiresAfter(self::REFUSED_TTL));

            throw new SourceUnavailableException($message);
        }

        // « QBT_SID_8080=…; HttpOnly; path=/ » : le nom du cookie varie selon la version.
        $value = explode(';', $cookie, 2)[0];
        $this->cache->save($session->set($value)->expiresAfter(self::SESSION_TTL));

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $url, array $options): ResponseInterface
    {
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options + ['timeout' => 5, 'max_redirects' => 0]);
            $response->getStatusCode();

            return $response;
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('qBittorrent injoignable : %s', $e->getMessage()), previous: $e);
        }
    }

    private function key(string $baseUrl, string $name): string
    {
        return 'qbittorrent.'.hash('xxh128', $baseUrl.'|'.$this->username).'.'.$name;
    }
}
