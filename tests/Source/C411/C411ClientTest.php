<?php

namespace App\Tests\Source\C411;

use App\Source\C411\C411Client;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class C411ClientTest extends TestCase
{
    private const BASE = 'https://c411.example';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    private function client(MockResponse $response, string $key = 'cle-api'): C411Client
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response) {
            $this->requests[] = [$method, $url, $options];

            return $response;
        });

        return new C411Client($http, new ArrayAdapter(), $key);
    }

    private static function profile(): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/c411-profile.json'), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    public function testReadsProfileWithBearerKey(): void
    {
        $client = $this->client(self::profile());

        $profile = $client->profile(self::BASE);

        self::assertSame('membre', $profile['username']);
        self::assertSame(37, $profile['level']['level']);
        [$method, $url, $options] = $this->requests[0];
        self::assertSame(['GET', 'https://c411.example/api/user/profile'], [$method, $url]);
        self::assertContains('Authorization: Bearer cle-api', $options['headers']);
    }

    public function testProfileIsCachedThirtySeconds(): void
    {
        // C411 ne rafraîchit pas ces données plus souvent ; la limite est de 30 requêtes/min.
        $client = $this->client(self::profile());

        $client->profile(self::BASE);
        $client->profile(self::BASE);

        self::assertCount(1, $this->requests);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function errors(): iterable
    {
        yield 'clé invalide' => [401, 'Clé API C411 refusée'];
        yield 'scope manquant' => [403, 'Profil (lecture)'];
        yield 'limite' => [429, 'nouvel essai dans une minute'];
        yield 'serveur' => [502, 'C411 a répondu 502'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(int $status, string $message): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage($message);

        $this->client(new MockResponse('{}', ['http_code' => $status]))->profile(self::BASE);
    }

    public function testRateLimitBacksOffForAMinute(): void
    {
        $client = $this->client(new MockResponse('{}', ['http_code' => 429]));

        foreach ([1, 2] as $attempt) {
            try {
                $client->profile(self::BASE);
                self::fail('exception attendue');
            } catch (SourceUnavailableException) {
            }
        }

        self::assertCount(1, $this->requests, 'pas de nouvel appel pendant la minute qui suit un 429');
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/C411 injoignable/');

        $this->client(new MockResponse('', ['error' => 'Could not resolve host']))->profile(self::BASE);
    }

    public function testMissingKey(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Clé API C411 absente (C411_API_SECRET)');

        $this->client(self::profile(), '')->profile(self::BASE);
    }
}
