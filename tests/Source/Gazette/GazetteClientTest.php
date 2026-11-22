<?php

namespace App\Tests\Source\Gazette;

use App\Source\Gazette\GazetteClient;
use App\Source\Gazette\GazetteIssue;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GazetteClientTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    private function client(MockResponse $response): GazetteClient
    {
        return new GazetteClient(new MockHttpClient(function (string $method, string $url) use ($response) {
            $this->urls[] = $url;

            return $response;
        }));
    }

    public function testIssues(): void
    {
        $issues = $this->client(new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/gazette-issues.json')))->issues('https://gazette.example/');

        self::assertSame(['https://gazette.example/api/issues'], $this->urls);
        self::assertCount(4, $issues);
        self::assertEquals(new GazetteIssue('c3d4e5f6', 4, '', '2026-11', 'brouillon', 5, null), $issues[0]);
        self::assertEquals(new GazetteIssue('b2c3d4e5', 3, 'La rentrée', '2026-10', 'envoye', 7, new \DateTimeImmutable('2026-10-30T19:12:00+00:00')), $issues[1]);
        self::assertTrue($issues[1]->sent());
        self::assertFalse($issues[0]->sent());
    }

    public function testNoIssue(): void
    {
        self::assertSame([], $this->client(new JsonMockResponse([]))->issues('https://gazette.example'));
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/^Gazette injoignable : /');

        $this->client(new MockResponse('', ['http_code' => 502]))->issues('https://gazette.example');
    }

    public function testHtmlInsteadOfJson(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/^Gazette injoignable : /');

        $this->client(new MockResponse('<!doctype html><title>Gazette</title>', ['response_headers' => ['content-type' => 'text/html']]))->issues('https://gazette.example');
    }
}
