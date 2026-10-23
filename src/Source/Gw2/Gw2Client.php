<?php

namespace App\Source\Gw2;

use App\Source\SourceUnavailableException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API officielle de Guild Wars 2 (v2), avec une clé de compte : lecture seule
 * par nature. La clé est passée à chaque appel : la carte choisit celle de qui
 * (variable d'environnement ou « Ma config » du profil). Textes en français (lang=fr) quand la route le permet. Icônes
 * publiques sur render.guildwars2.com.
 */
final readonly class Gw2Client
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function account(string $api, #[\SensitiveParameter] string $key): array
    {
        return $this->get($api, '/v2/account', $key);
    }

    /**
     * Droits de la clé : ['name' => …, 'permissions' => ['account', …]].
     *
     * @return array<string, mixed>
     */
    public function tokenInfo(string $api, #[\SensitiveParameter] string $key): array
    {
        return $this->get($api, '/v2/tokeninfo', $key);
    }

    /**
     * Monnaies demandées, dans cet ordre, avec nom et icône ; une monnaie
     * absente du portefeuille est ignorée.
     *
     * @param list<int> $ids
     *
     * @return list<array{id: int, name: string, icon: string, value: int}>
     */
    public function wallet(string $api, array $ids, #[\SensitiveParameter] string $key): array
    {
        $values = array_column($this->get($api, '/v2/account/wallet', $key), 'value', 'id');
        $currencies = array_column($this->public($api, '/v2/currencies', ['ids' => implode(',', $ids), 'lang' => 'fr']), null, 'id');

        $wallet = [];
        foreach ($ids as $id) {
            if (isset($values[$id], $currencies[$id])) {
                $wallet[] = ['id' => $id, 'name' => (string) $currencies[$id]['name'], 'icon' => (string) $currencies[$id]['icon'], 'value' => (int) $values[$id]];
            }
        }

        return $wallet;
    }

    /**
     * Chambre forte du Sorcier : objectifs de la période et méta-récompense.
     *
     * @param 'daily'|'weekly' $period
     *
     * @return array<string, mixed>
     */
    public function wizardsVault(string $api, string $period, #[\SensitiveParameter] string $key): array
    {
        return $this->get($api, '/v2/account/wizardsvault/'.$period, $key, ['lang' => 'fr']);
    }

    /**
     * Or et objets qui attendent au comptoir.
     *
     * @return array{coins: int, items: list<mixed>}
     */
    public function delivery(string $api, #[\SensitiveParameter] string $key): array
    {
        $delivery = $this->get($api, '/v2/commerce/delivery', $key);

        return ['coins' => (int) ($delivery['coins'] ?? 0), 'items' => array_values($delivery['items'] ?? [])];
    }

    /**
     * Artisanat quotidien : [faits aujourd'hui, possibles].
     *
     * @return array{int, int}
     */
    public function dailyCrafting(string $api, #[\SensitiveParameter] string $key): array
    {
        return [\count($this->get($api, '/v2/account/dailycrafting', $key)), \count($this->public($api, '/v2/dailycrafting'))];
    }

    /**
     * Icônes de l'interface du jeu (pièces, point de passage…), par identifiant.
     *
     * @param list<string> $ids identifiants de /v2/files : ui_coin_gold…
     *
     * @return array<string, string>
     */
    public function fileIcons(string $api, array $ids): array
    {
        return array_column($this->public($api, '/v2/files', ['ids' => implode(',', $ids)]), 'icon', 'id');
    }

    /**
     * Route du compte, avec sa clé.
     *
     * @param array<string, string> $query
     *
     * @return array<mixed>
     */
    private function get(string $api, string $path, #[\SensitiveParameter] string $key, array $query = []): array
    {
        if ('' === $key) {
            throw new SourceUnavailableException('Clé Guild Wars 2 absente');
        }

        return $this->request($api, $path, $query, ['Authorization' => 'Bearer '.$key]);
    }

    /**
     * Route publique (monnaies, icônes…) : sans clé.
     *
     * @param array<string, string> $query
     *
     * @return array<mixed>
     */
    private function public(string $api, string $path, array $query = []): array
    {
        return $this->request($api, $path, $query, []);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     */
    private function request(string $api, string $path, array $query, array $headers): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($api, '/').$path, ['query' => $query, 'headers' => ['X-Schema-Version' => 'latest'] + $headers, 'timeout' => 5]);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Clé Guild Wars 2 refusée (%d) sur %s : %s', $status, $path, $response->toArray(false)['text'] ?? 'droit manquant'));
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('API Guild Wars 2 injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
