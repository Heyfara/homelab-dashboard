<?php

namespace App\Tests\Source\Beszel;

use App\Source\Beszel\BeszelClient;
use App\Source\Beszel\BeszelContainer;
use App\Source\Beszel\BeszelSystem;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BeszelClientTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    /**
     * @param array<string, callable(array<string, mixed>): MockResponse> $routes début du chemin → réponse
     */
    private function client(array $routes, ?string $caFile = null): BeszelClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($routes) {
            $this->requests[] = [$method, $url, $options];
            $path = substr($url, \strlen('http://beszel:8090'));
            foreach ($routes as $prefix => $respond) {
                if (str_starts_with($path, $prefix)) {
                    return $respond($options);
                }
            }
            throw new \LogicException("Requête non prévue : $method $url");
        });

        return new BeszelClient($http, new ArrayAdapter(), 'http://beszel:8090/', 'lecteur@maison.example', 'secret', $caFile);
    }

    private static function auth(): callable
    {
        return static fn () => new JsonMockResponse(['token' => 'jeton-1', 'record' => ['id' => 'u1']]);
    }

    private static function systems(string $betaStatus = 'up'): callable
    {
        return static fn () => new JsonMockResponse(['items' => [
            ['id' => 'sys-alpha', 'name' => 'Alpha', 'status' => 'up'],
            ['id' => 'sys-beta', 'name' => 'Beta', 'status' => $betaStatus],
        ]]);
    }

    public function testSystemsByName(): void
    {
        $client = $this->client([
            '/api/collections/users/auth-with-password' => self::auth(),
            '/api/collections/systems/records' => self::systems('down'),
        ]);

        self::assertEquals([
            'Alpha' => new BeszelSystem('sys-alpha', 'Alpha', 'up'),
            'Beta' => new BeszelSystem('sys-beta', 'Beta', 'down'),
        ], $client->systems());
    }

    public function testSystemsCarryAddressAndCurrentInfo(): void
    {
        $client = $this->client([
            '/api/collections/users/auth-with-password' => self::auth(),
            '/api/collections/systems/records' => static fn () => new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/beszel/systems.json')),
        ]);

        $alpha = $client->systems()['Alpha'];

        self::assertSame('10.0.0.2', $alpha->host);
        self::assertSame(3.39, $alpha->info['cpu']);
        self::assertSame(['media' => 57.57], $alpha->info['efs']);
    }

    public function testStatsHistoryOfAPeriod(): void
    {
        $client = $this->client([
            '/api/collections/users/auth-with-password' => self::auth(),
            '/api/collections/systems/records' => static fn () => new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/beszel/systems.json')),
            '/api/collections/system_stats/records' => static fn () => new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/beszel/stats-alpha.json')),
        ]);

        $stats = $client->stats('Alpha', '20m');

        self::assertCount(2, $stats);
        self::assertEquals(new \DateTimeImmutable('2026-10-19T19:10:00Z'), $stats[0]->at);
        self::assertSame(3.53, $stats[1]->values['cpu']);

        [, $url] = end($this->requests);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame("system='sys-alpha' && type='20m'", $query['filter']);
        self::assertSame('created', $query['sort']);
    }

    public function testAuthenticatesWithPasswordThenSendsToken(): void
    {
        $client = $this->client([
            '/api/collections/users/auth-with-password' => static function (array $options) {
                self::assertSame('{"identity":"lecteur@maison.example","password":"secret"}', $options['body']);

                return new JsonMockResponse(['token' => 'jeton-1']);
            },
            '/api/collections/systems/records' => static function (array $options) {
                self::assertContains('Authorization: jeton-1', $options['headers']);

                return new JsonMockResponse(['items' => []]);
            },
        ]);

        $client->systems();
        $client->systems();

        self::assertCount(2, $this->requests, 'jeton et systèmes mis en cache');
    }

    public function testContainersOfASystem(): void
    {
        $client = $this->client([
            '/api/collections/users/auth-with-password' => self::auth(),
            '/api/collections/systems/records' => self::systems(),
            '/api/collections/containers/records' => static fn () => new JsonMockResponse(['items' => [
                ['id' => 'c1', 'name' => 'mealie', 'status' => 'Up 7 days', 'health' => 2, 'cpu' => 0.4, 'memory' => 312.5, 'updated' => 1792400000000],
                ['id' => 'c2', 'name' => 'actual_server', 'status' => 'Up 2 hours', 'health' => 0, 'cpu' => 0, 'memory' => 80, 'updated' => 1792400000000],
            ]]),
        ]);

        $containers = $client->containers('Beta');

        self::assertEquals(new BeszelContainer('c1', 'mealie', 'Up 7 days', 2, 0.4, 312.5, 1792400000), $containers['mealie']);
        self::assertArrayHasKey('actual_server', $containers);

        [, $url] = end($this->requests);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame("system='sys-beta'", $query['filter']);
    }

    public function testUnknownSystem(): void
    {
        $client = $this->client([
            '/api/collections/users/auth-with-password' => self::auth(),
            '/api/collections/systems/records' => self::systems(),
        ]);

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Beszel ne connaît pas le système « Gamma » (systèmes visibles : Alpha, Beta)');

        $client->containers('Gamma');
    }

    public function testExpiredTokenIsRenewedOnce(): void
    {
        $tokens = ['jeton-1', 'jeton-2'];
        $client = $this->client([
            '/api/collections/users/auth-with-password' => static function () use (&$tokens) {
                return new JsonMockResponse(['token' => array_shift($tokens)]);
            },
            '/api/collections/systems/records' => static fn (array $options) => \in_array('Authorization: jeton-1', $options['headers'], true)
                ? new MockResponse('{"message":"expired"}', ['http_code' => 401])
                : new JsonMockResponse(['items' => []]),
        ]);

        self::assertSame([], $client->systems());
        self::assertCount(4, $this->requests);
    }

    public function testBadCredentials(): void
    {
        $client = $this->client([
            '/api/collections/users/auth-with-password' => static fn () => new MockResponse('{"message":"Failed to authenticate."}', ['http_code' => 400]),
        ]);

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/connexion à Beszel refusée/i');

        $client->systems();
    }

    public function testUnreachable(): void
    {
        $client = $this->client([
            '/api/collections/users/auth-with-password' => static fn () => new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Beszel injoignable/');

        $client->systems();
    }

    public function testNotConfigured(): void
    {
        $client = new BeszelClient(new MockHttpClient(), new ArrayAdapter(), '', '', '');

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage("Beszel n'est pas configuré (BESZEL_URL)");

        $client->systems();
    }

    public function testCustomCaFileIsUsed(): void
    {
        $client = $this->client([
            '/api/collections/users/auth-with-password' => self::auth(),
            '/api/collections/systems/records' => static fn () => new JsonMockResponse(['items' => []]),
        ], '/certs/rootCA.pem');

        $client->systems();

        foreach ($this->requests as [, , $options]) {
            self::assertSame('/certs/rootCA.pem', $options['cafile']);
        }
    }
}
