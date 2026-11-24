<?php

namespace App\Tests\Source\Freebox;

use App\Source\Freebox\FreeboxClient;
use App\Source\Freebox\FreeboxConnection;
use App\Source\Freebox\FreeboxHost;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FreeboxClientTest extends TestCase
{
    private const URL = 'http://box.test';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];
    private int $logins = 0;

    /**
     * @param callable(string, string): ?MockResponse $respond méthode + chemin → réponse (null = réponse par défaut)
     */
    private function client(?callable $respond = null, string $token = 'jeton-app', ?ArrayAdapter $cache = null): FreeboxClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$method, $url, $options];
            $path = (string) parse_url($url, \PHP_URL_PATH);
            if (null !== $respond && null !== $r = $respond($method, $path)) {
                return $r;
            }

            return match ($path) {
                '/api/v8/login/' => new JsonMockResponse(['success' => true, 'result' => ['logged_in' => false, 'challenge' => 'defi']]),
                '/api/v8/login/session/' => $this->session(),
                default => new JsonMockResponse(['success' => false, 'error_code' => 'not_found'], ['http_code' => 404]),
            };
        });

        return new FreeboxClient($http, $cache ?? new ArrayAdapter(), self::URL, $token);
    }

    private function session(): JsonMockResponse
    {
        ++$this->logins;

        return new JsonMockResponse(['success' => true, 'result' => ['session_token' => 'session-'.$this->logins, 'permissions' => []]]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function host(array $overrides = []): array
    {
        return array_replace([
            'id' => 'ether-00:11:22:33:44:55',
            'l2ident' => ['id' => '00:11:22:33:44:55', 'type' => 'mac_address'],
            'primary_name' => 'portable',
            'vendor_name' => 'Fabricant',
            'host_type' => 'laptop',
            'active' => true,
            'reachable' => true,
            'last_activity' => 1795000000,
            'first_activity' => 1790000000,
            'l3connectivities' => [
                ['addr' => 'fe80::1', 'af' => 'ipv6', 'active' => true, 'reachable' => true, 'last_activity' => 1795000000],
                ['addr' => '192.168.1.40', 'af' => 'ipv4', 'active' => false, 'reachable' => false, 'last_activity' => 1790000000],
                ['addr' => '192.168.1.41', 'af' => 'ipv4', 'active' => true, 'reachable' => true, 'last_activity' => 1795000000],
            ],
        ], $overrides);
    }

    public function testHostsOpenASessionSignedWithTheAppToken(): void
    {
        $client = $this->client(static fn (string $m, string $path) => '/api/v8/lan/browser/pub/' === $path
            ? new JsonMockResponse(['success' => true, 'result' => [self::host()]])
            : null);

        $hosts = $client->hosts();

        self::assertEquals([new FreeboxHost(
            mac: '00:11:22:33:44:55',
            name: 'portable',
            vendor: 'Fabricant',
            type: 'laptop',
            ipv4: '192.168.1.41',
            active: true,
            lastActivity: new \DateTimeImmutable('@1795000000'),
        )], $hosts);

        [$method, , $options] = $this->requests[1];
        self::assertSame('POST', $method);
        self::assertSame(['app_id' => FreeboxClient::APP_ID, 'password' => hash_hmac('sha1', 'defi', 'jeton-app')], json_decode($options['body'], true));
        self::assertContains('X-Fbx-App-Auth: session-1', $this->requests[2][2]['headers']);
    }

    public function testHostWithoutIpv4OrNameKeepsWhatItHas(): void
    {
        $client = $this->client(static fn (string $m, string $path) => '/api/v8/lan/browser/pub/' === $path
            ? new JsonMockResponse(['success' => true, 'result' => [self::host(['primary_name' => '', 'vendor_name' => '', 'l3connectivities' => [], 'active' => false])]])
            : null);

        $host = $client->hosts()[0];

        self::assertNull($host->ipv4);
        self::assertNull($host->name);
        self::assertNull($host->vendor);
        self::assertFalse($host->active);
    }

    public function testSessionIsReusedThenRenewedWhenTheBoxForgetsIt(): void
    {
        $expired = false;
        $client = $this->client(function (string $m, string $path) use (&$expired) {
            if ('/api/v8/lan/browser/pub/' !== $path) {
                return null;
            }
            if ($expired) {
                $expired = false;

                return new JsonMockResponse(['success' => false, 'error_code' => 'auth_required', 'msg' => 'Session invalide'], ['http_code' => 403]);
            }

            return new JsonMockResponse(['success' => true, 'result' => []]);
        });

        $client->hosts();
        $client->hosts();
        self::assertSame(1, $this->logins);

        $expired = true;
        $client->hosts();
        self::assertSame(2, $this->logins);
        self::assertContains('X-Fbx-App-Auth: session-2', end($this->requests)[2]['headers']);
    }

    public function testStaticLeasesByMac(): void
    {
        $client = $this->client(static fn (string $m, string $path) => '/api/v8/dhcp/static_lease/' === $path
            ? new JsonMockResponse(['success' => true, 'result' => [
                ['mac' => 'aa:bb:cc:dd:ee:ff', 'ip' => '192.168.1.2', 'comment' => 'serveur'],
                ['mac' => '00:11:22:33:44:55', 'ip' => '192.168.1.41', 'comment' => ''],
            ]])
            : null);

        self::assertSame(['AA:BB:CC:DD:EE:FF' => '192.168.1.2', '00:11:22:33:44:55' => '192.168.1.41'], $client->staticLeases());
    }

    public function testNoStaticLeaseAtAll(): void
    {
        // La Freebox omet `result` quand la liste est vide.
        $client = $this->client(static fn (string $m, string $path) => '/api/v8/dhcp/static_lease/' === $path
            ? new JsonMockResponse(['success' => true])
            : null);

        self::assertSame([], $client->staticLeases());
    }

    public function testConnection(): void
    {
        $client = $this->client(static fn (string $m, string $path) => '/api/v8/connection/' === $path
            ? new JsonMockResponse(['success' => true, 'result' => [
                'state' => 'up', 'type' => 'ethernet', 'media' => 'ftth',
                'rate_down' => 1250000, 'rate_up' => 62500,
                'bandwidth_down' => 1000000000, 'bandwidth_up' => 700000000,
                'ipv4' => '203.0.113.7',
            ]])
            : null);

        self::assertEquals(new FreeboxConnection('up', 'ftth', 1250000, 62500, 125000000, 87500000), $client->connection());
    }

    public function testMissingTokenIsExplained(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('FREEBOX_APP_TOKEN');

        $this->client(token: '')->hosts();
    }

    public function testRefusedLoginIsExplained(): void
    {
        $client = $this->client(static fn (string $m, string $path) => '/api/v8/login/session/' === $path
            ? new JsonMockResponse(['success' => false, 'error_code' => 'invalid_token', 'msg' => 'Jeton inconnu'], ['http_code' => 403])
            : null);

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Connexion à la Freebox refusée (invalid_token)');

        $client->hosts();
    }

    public function testUnreachableBox(): void
    {
        $client = $this->client(static fn () => new MockResponse('', ['error' => 'Connection refused']));

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Freebox injoignable');

        $client->connection();
    }

    public function testInsufficientRightsAreReported(): void
    {
        $client = $this->client(static fn (string $m, string $path) => '/api/v8/connection/' === $path
            ? new JsonMockResponse(['success' => false, 'error_code' => 'insufficient_rights', 'msg' => 'Accès refusé'], ['http_code' => 403])
            : null);

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('La Freebox a refusé /connection/ (insufficient_rights)');

        $client->connection();
    }

    public function testRequestAuthorizationNeedsNoSession(): void
    {
        $client = $this->client(static fn (string $m, string $path) => match ($path) {
            '/api/v8/login/authorize/' => new JsonMockResponse(['success' => true, 'result' => ['app_token' => 'nouveau-jeton', 'track_id' => 42]]),
            '/api/v8/login/authorize/42' => new JsonMockResponse(['success' => true, 'result' => ['status' => 'granted', 'challenge' => 'x']]),
            default => null,
        }, token: '');

        self::assertSame(['token' => 'nouveau-jeton', 'track' => 42], $client->requestAuthorization('poste'));
        self::assertSame('granted', $client->authorizationStatus(42));

        [$method, , $options] = $this->requests[0];
        self::assertSame('POST', $method);
        self::assertSame(['app_id' => FreeboxClient::APP_ID, 'app_name' => 'Homelab Dashboard', 'app_version' => '1', 'device_name' => 'poste'], json_decode($options['body'], true));
        self::assertSame(0, $this->logins);
    }
}
