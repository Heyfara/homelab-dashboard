<?php

namespace App\Source\Github;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Recherche de pull requests ouvertes sur GitHub (API de recherche) : une seule
 * requête pour plusieurs dépôts. Limite : 30 recherches par minute avec un jeton,
 * 10 sans (dépôts publics seulement).
 */
final readonly class GithubClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(GITHUB_TOKEN)%')]
        #[\SensitiveParameter]
        private string $token,
    ) {
    }

    /**
     * @param list<string> $repos  « propriétaire/nom »
     * @param list<string> $labels toutes requises
     */
    public function searchPullRequests(string $api, array $repos, ?string $author, array $labels, int $limit): GithubSearchResult
    {
        $headers = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
        if ('' !== $this->token) {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($api, '/').'/search/issues', [
                'query' => ['q' => self::query($repos, $author, $labels), 'sort' => 'updated', 'order' => 'desc', 'per_page' => (string) $limit],
                'headers' => $headers,
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw new SourceUnavailableException(self::error($status, $response->getHeaders(false), $response->toArray(false)));
            }
            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('GitHub injoignable : %s', $e->getMessage()), previous: $e);
        }

        return new GithubSearchResult((int) $data['total_count'], array_map(self::pullRequest(...), $data['items']));
    }

    /**
     * Même recherche sur github.com, pour le lien « Tout voir ».
     *
     * @param list<string> $repos
     * @param list<string> $labels
     */
    public static function webSearchUrl(array $repos, ?string $author, array $labels): string
    {
        return 'https://github.com/search?'.http_build_query(['q' => self::query($repos, $author, $labels), 'type' => 'pullrequests']);
    }

    /**
     * @param list<string> $repos
     * @param list<string> $labels
     */
    private static function query(array $repos, ?string $author, array $labels): string
    {
        $q = ['is:pr', 'is:open', 'archived:false'];
        foreach ($repos as $repo) {
            $q[] = 'repo:'.$repo;
        }
        if (null !== $author) {
            $q[] = 'author:'.$author;
        }
        foreach ($labels as $label) {
            $q[] = \sprintf('label:"%s"', $label);
        }

        return implode(' ', $q);
    }

    /**
     * @param array<string, mixed> $i
     */
    private static function pullRequest(array $i): GithubPullRequest
    {
        return new GithubPullRequest(
            preg_replace('#^.*/repos/#', '', $i['repository_url']),
            (int) $i['number'],
            $i['title'],
            $i['html_url'],
            $i['user']['login'] ?? '',
            (bool) ($i['draft'] ?? false),
            array_map(static fn (array $l): array => ['name' => $l['name'], 'color' => '#'.$l['color']], $i['labels'] ?? []),
            new \DateTimeImmutable($i['created_at']),
            new \DateTimeImmutable($i['updated_at']),
        );
    }

    /**
     * @param array<string, list<string>> $headers
     * @param array<string, mixed>        $body
     */
    private static function error(int $status, array $headers, array $body): string
    {
        if (401 === $status) {
            return 'Jeton GitHub refusé (401), vérifier GITHUB_TOKEN';
        }
        if (\in_array($status, [403, 429], true) && '0' === ($headers['x-ratelimit-remaining'][0] ?? null)) {
            $minutes = max(1, (int) ceil(((int) ($headers['x-ratelimit-reset'][0] ?? 0) - time()) / 60));

            return \sprintf('Limite de l\'API GitHub atteinte (%d) ; reprise dans %d min', $status, $minutes);
        }
        if (422 === $status) {
            return \sprintf('GitHub refuse la recherche : %s', $body['errors'][0]['message'] ?? $body['message'] ?? 'requête invalide');
        }

        return \sprintf('GitHub a répondu %d%s', $status, isset($body['message']) ? ' : '.$body['message'] : '');
    }
}
