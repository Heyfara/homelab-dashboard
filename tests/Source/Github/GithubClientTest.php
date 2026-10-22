<?php

namespace App\Tests\Source\Github;

use App\Source\Github\GithubClient;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GithubClientTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    private function client(MockResponse $response, string $token = 'ghp_test'): GithubClient
    {
        return new GithubClient(new MockHttpClient(function (string $method, string $url, array $options) use ($response) {
            $this->requests[] = [$method, $url, $options];

            return $response;
        }), $token);
    }

    private static function search(): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/github-search.json'));
    }

    public function testSearchesOpenPullRequestsAcrossRepos(): void
    {
        $result = $this->client(self::search())->searchPullRequests('https://api.github.com', ['exemple/infra', 'exemple/app'], 'app/renovate', ['revue-requise'], 8);

        self::assertSame(3, $result->total);
        self::assertCount(2, $result->items);
        $first = $result->items[0];
        self::assertSame(['exemple/infra', 61, 'renovate[bot]', false], [$first->repo, $first->number, $first->author, $first->draft]);
        self::assertSame([['name' => 'revue-requise', 'color' => '#d93f0b'], ['name' => 'backup-avant', 'color' => '#b60205']], $first->labels);
        self::assertEquals(new \DateTimeImmutable('2026-10-17T06:12:00Z'), $first->createdAt);
        self::assertTrue($result->items[1]->draft);

        [$method, $url, $options] = $this->requests[0];
        self::assertSame('GET', $method);
        self::assertStringStartsWith('https://api.github.com/search/issues?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame('is:pr is:open archived:false repo:exemple/infra repo:exemple/app author:app/renovate label:"revue-requise"', $query['q']);
        self::assertSame(['updated', 'desc', '8'], [$query['sort'], $query['order'], $query['per_page']]);
        self::assertContains('Authorization: Bearer ghp_test', $options['headers']);
        self::assertContains('Accept: application/vnd.github+json', $options['headers']);
    }

    public function testWithoutTokenOnlyPublicRepos(): void
    {
        $this->client(self::search(), '')->searchPullRequests('https://api.github.com', ['exemple/app'], null, [], 5);

        foreach ($this->requests[0][2]['headers'] as $header) {
            self::assertStringStartsNotWith('Authorization', $header);
        }
        parse_str((string) parse_url($this->requests[0][1], \PHP_URL_QUERY), $query);
        self::assertSame('is:pr is:open archived:false repo:exemple/app', $query['q']);
    }

    public function testWebSearchUrl(): void
    {
        self::assertSame(
            'https://github.com/search?q=is%3Apr+is%3Aopen+archived%3Afalse+repo%3Aexemple%2Fapp&type=pullrequests',
            GithubClient::webSearchUrl(['exemple/app'], null, []),
        );
    }

    /**
     * @return iterable<string, array{int, array<string, string>, string, string}>
     */
    public static function errors(): iterable
    {
        yield 'jeton refusé' => [401, [], '{"message":"Bad credentials"}', 'Jeton GitHub refusé'];
        yield 'limite' => [403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1792400000'], '{"message":"API rate limit exceeded"}', 'Limite de l\'API GitHub atteinte'];
        yield 'dépôt inaccessible' => [422, [], '{"message":"Validation Failed","errors":[{"message":"The listed users and repositories cannot be searched either because the resources do not exist or you do not have permission to view them."}]}', 'cannot be searched'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(int $status, array $headers, string $body, string $message): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage($message);

        $this->client(new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]))->searchPullRequests('https://api.github.com', ['exemple/app'], null, [], 5);
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/GitHub injoignable/');

        $this->client(new MockResponse('', ['error' => 'Could not resolve host']))->searchPullRequests('https://api.github.com', ['exemple/app'], null, [], 5);
    }
}
