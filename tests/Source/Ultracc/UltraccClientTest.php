<?php

namespace App\Tests\Source\Ultracc;

use App\Source\SourceUnavailableException;
use App\Source\Ultracc\UltraccClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UltraccClientTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    private function client(MockResponse $response, string $token = 'jeton'): UltraccClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response) {
            $this->requests[] = [$method, $url, $options];

            return $response;
        });

        return new UltraccClient($http, $token);
    }

    private static function stats(): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/ultracc-total-stats.json'), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    public function testReadsTotalStatsWithBearerToken(): void
    {
        $stats = $this->client(self::stats())->stats('https://tom.serveur.usbx.example/qbittorrent');

        [$method, $url, $options] = $this->requests[0];
        self::assertSame(['GET', 'https://tom.serveur.usbx.example/ultra-api/total-stats'], [$method, $url]);
        self::assertContains('Authorization: Bearer jeton', $options['headers']);

        // L'API compte en Gio (quota -s) : converti en octets.
        self::assertSame(632 * 1024 ** 3, $stats->usedBytes);
        self::assertSame(932 * 1024 ** 3, $stats->totalBytes);
        self::assertSame(322122547200, $stats->freeBytes);
        self::assertSame(24.5, $stats->trafficUsedPercent);
        self::assertEquals(new \DateTimeImmutable('2026-03-10T00:00:00Z'), $stats->lastTrafficReset);
        self::assertEquals(new \DateTimeImmutable('2026-04-10T00:00:00Z'), $stats->nextTrafficReset);
    }

    public function testConvertsStorageUnitsToBytes(): void
    {
        $json = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/ultracc-total-stats.json'), true);
        $json['service_stats_info']['total_storage_value'] = 2;
        $json['service_stats_info']['total_storage_unit'] = 'T';
        $json['service_stats_info']['used_storage_value'] = 512;
        $json['service_stats_info']['used_storage_unit'] = 'M';

        $stats = $this->client(new MockResponse(json_encode($json)))->stats('https://tom.serveur.usbx.example');

        self::assertSame(2 * 1024 ** 4, $stats->totalBytes);
        self::assertSame(512 * 1024 ** 2, $stats->usedBytes);
    }

    public function testMissingTokenIsExplained(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('ULTRACC_API_TOKEN');

        $this->client(self::stats(), '')->stats('https://tom.serveur.usbx.example');
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function errors(): iterable
    {
        yield 'jeton refusé' => [401, 'Jeton de l\'API Ultra.cc refusé'];
        yield 'API non installée' => [404, 'API Ultra.cc introuvable'];
        yield 'serveur' => [502, 'L\'API Ultra.cc a répondu 502'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrors(int $status, string $message): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage($message);

        $this->client(new MockResponse('<html></html>', ['http_code' => $status]))->stats('https://tom.serveur.usbx.example');
    }

    public function testUnexpectedBody(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Réponse inattendue');

        $this->client(new MockResponse('{"autre":{}}'))->stats('https://tom.serveur.usbx.example');
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('API Ultra.cc injoignable');

        $this->client(new MockResponse('', ['error' => 'timeout']))->stats('https://tom.serveur.usbx.example');
    }
}
