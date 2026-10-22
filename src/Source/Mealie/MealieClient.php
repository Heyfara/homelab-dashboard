<?php

namespace App\Source\Mealie;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API de Mealie (v3), avec un jeton d'API. Les photos de recettes sont
 * publiques : le navigateur les charge directement.
 */
final readonly class MealieClient
{
    /** Assez pour toutes les recettes d'une famille, en un appel. */
    private const MAX_RECIPES = 200;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(MEALIE_API_TOKEN)%')]
        #[\SensitiveParameter]
        private string $token,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(HTTP_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * @return list<MealieRecipe> les plus récentes d'abord
     */
    public function recipes(string $baseUrl): array
    {
        $data = $this->get($baseUrl, '/api/recipes', ['page' => '1', 'perPage' => (string) self::MAX_RECIPES, 'orderBy' => 'created_at', 'orderDirection' => 'desc']);

        return array_map(self::recipe(...), $data['items'] ?? []);
    }

    /**
     * @return list<MealieMeal>
     */
    public function mealPlan(string $baseUrl, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $data = $this->get($baseUrl, '/api/households/mealplans', [
            'start_date' => $from->format('Y-m-d'),
            'end_date' => $to->format('Y-m-d'),
            'perPage' => '100',
            'orderBy' => 'date',
            'orderDirection' => 'asc',
        ]);

        return array_map(static fn (array $m): MealieMeal => new MealieMeal(
            $m['date'],
            $m['entryType'] ?? 'dinner',
            (string) ($m['title'] ?? ''),
            isset($m['recipe']) ? self::recipe($m['recipe']) : null,
        ), $data['items'] ?? []);
    }

    /** Groupe du compte, pour les liens vers les recettes (/g/<groupe>/r/<recette>). */
    public function groupSlug(string $baseUrl): string
    {
        return (string) ($this->get($baseUrl, '/api/users/self', [])['groupSlug'] ?? 'home');
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function recipe(array $r): MealieRecipe
    {
        return new MealieRecipe($r['id'], $r['slug'], $r['name'], null !== ($r['image'] ?? null), $r['totalTime'] ?? null, isset($r['rating']) ? (float) $r['rating'] : null);
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $baseUrl, string $path, array $query): array
    {
        if ('' === $this->token) {
            throw new SourceUnavailableException('Jeton Mealie absent (MEALIE_API_TOKEN)');
        }
        $options = ['query' => $query, 'headers' => ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'], 'timeout' => 5];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Jeton Mealie refusé (%d), vérifier MEALIE_API_TOKEN', $status));
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('Mealie injoignable : %s', $e->getMessage()), previous: $e);
        }
    }
}
