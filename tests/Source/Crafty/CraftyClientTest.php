<?php

namespace App\Tests\Source\Crafty;

use App\Source\Crafty\CraftyClient;
use App\Source\Crafty\CraftyServer;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CraftyClientTest extends TestCase
{
    private const BASE = 'http://crafty:8443';

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    private function client(?callable $respond = null, string $token = 'cle'): CraftyClient
    {
        $respond ??= static fn (string $path) => self::homelab($path);

        return new CraftyClient(new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$url, $options];

            return $respond((string) parse_url($url, \PHP_URL_PATH));
        }), $token);
    }

    /** Crafty fictif, réutilisé par FakeHomelab. */
    public static function homelab(string $path): MockResponse
    {
        $file = match (true) {
            '/api/v2/servers' === $path => 'crafty-servers.json',
            1 === preg_match('#^/api/v2/servers/(s[1-4])/stats$#', $path, $m) => 'crafty-stats-'.$m[1].'.json',
            default => throw new \LogicException('Requête non prévue : '.$path),
        };

        return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/'.$file), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    /**
     * @return array<string, CraftyServer>
     */
    private function servers(): array
    {
        $servers = [];
        foreach ($this->client()->servers(self::BASE) as $server) {
            $servers[$server->name] = $server;
        }

        return $servers;
    }

    public function testRunningServerWithPlayersFromPythonList(): void
    {
        $s = $this->servers()['Monde survie'];

        self::assertSame(['s1', 25566, 'online', 2, 10, ['Steve', 'Alex'], 'Paper 26.2', 'Monde de la famille', '423.2MB'], [$s->id, $s->port, $s->state, $s->online, $s->max, $s->players, $s->version, $s->motd, $s->worldSize]);
        self::assertStringStartsWith('data:image/png;base64,iVBORw0KGg', (string) $s->icon);
        self::assertStringNotContainsString("\n", (string) $s->icon);
        self::assertEquals(new \DateTimeImmutable('2026-10-20 07:30:00'), $s->startedAt);
    }

    public function testRunningWithoutPlayers(): void
    {
        $s = $this->servers()['Public'];

        self::assertSame(['online', 0, 20, [], null], [$s->state, $s->online, $s->max, $s->players, $s->icon]);
    }

    public function testStoppedServerHasNoPingData(): void
    {
        $s = $this->servers()['Ancien monde'];

        self::assertSame(['stopped', [], null, null, null], [$s->state, $s->players, $s->version, $s->motd, $s->startedAt]);
        self::assertSame('2.1GB', $s->worldSize);
    }

    public function testCrashed(): void
    {
        self::assertSame('crashed', $this->servers()['Planté']->state);
    }

    public function testBearerKeyAndOneStatsCallPerServer(): void
    {
        $this->client()->servers(self::BASE);

        self::assertCount(5, $this->requests);
        self::assertContains('Authorization: Bearer cle', $this->requests[0][1]['headers']);
        self::assertStringNotContainsString('SECRET', serialize($this->client()->servers(self::BASE)), 'commande de lancement, chemins : jamais gardés');
    }

    public function testRefusedKey(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Clé Crafty refusée (403)');

        $this->client(static fn () => new MockResponse('{"status":"error","error":"ACCESS_DENIED"}', ['http_code' => 403]))->servers(self::BASE);
    }

    public function testMissingKey(): void
    {
        $this->expectExceptionMessage('CRAFTY_API_TOKEN');

        $this->client(token: '')->servers(self::BASE);
    }

    public function testUnreachable(): void
    {
        $this->expectExceptionMessage('Crafty injoignable');

        $this->client(static fn () => new MockResponse('', ['error' => 'Connexion refusée']))->servers(self::BASE);
    }
}
