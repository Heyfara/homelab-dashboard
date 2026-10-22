<?php

namespace App\Source\Beszel;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lecture de l'API du hub Beszel (PocketBase) avec un compte en lecture seule.
 * Le compte ne voit que les systèmes dont il est membre.
 */
final readonly class BeszelClient
{
    /** Les agents envoient leurs mesures chaque minute : inutile de relire plus souvent. */
    private const LIST_TTL = 15;
    private const TOKEN_TTL = 3600;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire('%env(BESZEL_URL)%')]
        private string $url,
        #[Autowire('%env(BESZEL_EMAIL)%')]
        private string $email,
        #[Autowire('%env(BESZEL_PASSWORD)%')]
        #[\SensitiveParameter]
        private string $password,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * @return array<string, BeszelSystem> par nom
     */
    public function systems(): array
    {
        return $this->cache->get($this->key('systems'), function (ItemInterface $item): array {
            $item->expiresAfter(self::LIST_TTL);
            $systems = [];
            foreach ($this->list('systems', ['fields' => 'id,name,status,host,info']) as $s) {
                $systems[$s['name']] = new BeszelSystem($s['id'], $s['name'], $s['status'], $s['host'] ?? '', $s['info'] ?? []);
            }

            return $systems;
        });
    }

    /**
     * @return array<string, BeszelContainer> par nom
     */
    public function containers(string $systemName): array
    {
        $system = $this->system($systemName);

        return $this->cache->get($this->key('containers.'.$system->id), function (ItemInterface $item) use ($system): array {
            $item->expiresAfter(self::LIST_TTL);
            $containers = [];
            $records = $this->list('containers', [
                'fields' => 'id,name,status,health,cpu,memory,updated',
                'filter' => \sprintf("system='%s'", $system->id),
            ]);
            foreach ($records as $c) {
                $containers[$c['name']] = new BeszelContainer($c['id'], $c['name'], $c['status'], (int) $c['health'], (float) $c['cpu'], (float) $c['memory'], intdiv((int) $c['updated'], 1000));
            }

            return $containers;
        });
    }

    /**
     * Mesures d'un système sur la résolution `type` (1m, 10m, 20m, 120m, 480m),
     * de la plus ancienne à la plus récente. Beszel en garde ~1 h en 1m, 12 h en
     * 10m, 24 h en 20m, 7 j en 120m et 30 j en 480m.
     *
     * @return list<BeszelStat>
     */
    public function stats(string $systemName, string $type): array
    {
        $system = $this->system($systemName);

        return $this->cache->get($this->key('stats.'.$system->id.'.'.$type), function (ItemInterface $item) use ($system, $type): array {
            $item->expiresAfter(self::LIST_TTL);

            return array_map(static fn (array $r): BeszelStat => new BeszelStat(new \DateTimeImmutable($r['created']), $r['stats'] ?? []), $this->list('system_stats', [
                'fields' => 'created,stats',
                'filter' => \sprintf("system='%s' && type='%s'", $system->id, $type),
                'sort' => 'created',
            ]));
        });
    }

    public function system(string $systemName): BeszelSystem
    {
        return $this->systems()[$systemName] ?? throw new SourceUnavailableException(\sprintf(
            'Beszel ne connaît pas le système « %s » (systèmes visibles : %s)',
            $systemName,
            implode(', ', array_keys($this->systems())) ?: 'aucun, le compte est-il membre des systèmes ?',
        ));
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function list(string $collection, array $query): array
    {
        $query += ['perPage' => '500', 'skipTotal' => '1'];
        $path = \sprintf('/api/collections/%s/records', $collection);

        try {
            $response = $this->request('GET', $path, ['query' => $query, 'headers' => ['Authorization' => $this->token()]]);
            if (401 === $response->getStatusCode()) {
                // Jeton expiré ou révoqué : un seul nouvel essai.
                $this->cache->delete($this->key('token'));
                $response = $this->request('GET', $path, ['query' => $query, 'headers' => ['Authorization' => $this->token()]]);
            }

            return $response->toArray()['items'] ?? [];
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Beszel injoignable : %s', $e->getMessage()), previous: $e);
        }
    }

    private function token(): string
    {
        return $this->cache->get($this->key('token'), function (ItemInterface $item): string {
            $item->expiresAfter(self::TOKEN_TTL);
            $response = $this->request('POST', '/api/collections/users/auth-with-password', [
                'json' => ['identity' => $this->email, 'password' => $this->password],
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new SourceUnavailableException(\sprintf('Connexion à Beszel refusée pour %s (HTTP %d), vérifier BESZEL_EMAIL et BESZEL_PASSWORD', $this->email, $response->getStatusCode()));
            }

            return $response->toArray()['token'];
        });
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $path, array $options): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        if ('' === $this->url) {
            throw new SourceUnavailableException("Beszel n'est pas configuré (BESZEL_URL)");
        }
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->url, '/').$path, $options + ['timeout' => 3]);
            $response->getStatusCode();

            return $response;
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Beszel injoignable : %s', $e->getMessage()), previous: $e);
        }
    }

    private function key(string $name): string
    {
        return 'beszel.'.hash('xxh128', $this->url.'|'.$this->email).'.'.$name;
    }
}
