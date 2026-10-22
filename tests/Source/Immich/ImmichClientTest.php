<?php

namespace App\Tests\Source\Immich;

use App\Card\Image\CardImage;
use App\Source\Immich\ImmichAsset;
use App\Source\Immich\ImmichClient;
use App\Source\Immich\ImmichMemory;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ImmichClientTest extends TestCase
{
    private const BASE = 'http://immich:2283';
    private const P1 = '0a3c1d6e-5b2f-4c8a-9d1e-2f3a4b5c6d71';
    private const P2 = '0a3c1d6e-5b2f-4c8a-9d1e-2f3a4b5c6d72';
    private const V1 = '0a3c1d6e-5b2f-4c8a-9d1e-2f3a4b5c6d73';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    private function client(?callable $respond = null, string $key = 'cle'): ImmichClient
    {
        $respond ??= self::homelab(...);

        return new ImmichClient(new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$method, $url, $options];

            return $respond((string) parse_url($url, \PHP_URL_PATH));
        }), $key);
    }

    /** Réponses d'un Immich fictif, réutilisées par FakeHomelab. */
    public static function homelab(string $path): MockResponse
    {
        $asset = static fn (string $id, string $type, string $local) => ['id' => $id, 'type' => $type, 'localDateTime' => $local, 'fileCreatedAt' => $local, 'originalFileName' => 'IMG.jpg'];

        return match ($path) {
            '/api/assets/statistics' => new JsonMockResponse(['images' => 1520, 'videos' => 87, 'total' => 1607]),
            '/api/search/metadata' => new JsonMockResponse(['albums' => ['items' => []], 'assets' => ['count' => 3, 'total' => 3, 'nextPage' => null, 'items' => [
                $asset(self::P1, 'IMAGE', '2026-10-19T16:42:10.000Z'),
                $asset(self::V1, 'VIDEO', '2026-10-18T10:00:00.000Z'),
                $asset(self::P2, 'IMAGE', '2026-10-17T09:30:00.000Z'),
            ]]]),
            '/api/memories' => new JsonMockResponse([
                ['id' => 'mem-2023', 'type' => 'on_this_day', 'data' => ['year' => 2023], 'isSaved' => false, 'memoryAt' => '2023-10-20T00:00:00.000Z', 'assets' => [$asset(self::P2, 'IMAGE', '2023-10-20T14:00:00.000Z'), $asset(self::P1, 'IMAGE', '2023-10-20T15:00:00.000Z')]],
                ['id' => 'mem-vide', 'type' => 'on_this_day', 'data' => ['year' => 2020], 'isSaved' => false, 'memoryAt' => '2020-10-20T00:00:00.000Z', 'assets' => []],
                ['id' => 'mem-2019', 'type' => 'on_this_day', 'data' => ['year' => 2019], 'isSaved' => false, 'memoryAt' => '2019-10-20T00:00:00.000Z', 'assets' => [$asset(self::V1, 'VIDEO', '2019-10-20T08:00:00.000Z')]],
            ]),
            '/api/assets/'.self::P1.'/thumbnail', '/api/assets/'.self::P2.'/thumbnail', '/api/assets/'.self::V1.'/thumbnail' => new MockResponse('octets', ['response_headers' => ['Content-Type' => 'image/webp']]),
            default => throw new \LogicException('Requête non prévue : '.$path),
        };
    }

    public function testStatistics(): void
    {
        self::assertSame(['images' => 1520, 'videos' => 87], $this->client()->statistics(self::BASE));
        self::assertContains('x-api-key: cle', $this->requests[0][2]['headers']);
    }

    public function testRecentAssetsNewestFirstWithoutHiddenOrArchived(): void
    {
        $assets = $this->client()->recent(self::BASE, 3);

        self::assertEquals(new ImmichAsset(self::P1, false, new \DateTimeImmutable('2026-10-19T16:42:10')), $assets[0]);
        self::assertTrue($assets[1]->video);
        [$method, , $options] = $this->requests[0];
        self::assertSame('POST', $method);
        $body = json_decode($options['body'], true);
        self::assertSame(3, $body['size']);
        self::assertSame(['field' => 'fileCreatedAt', 'direction' => 'desc'], $body['orderBy']);
        self::assertSame(['eq' => 'timeline'], $body['filter']['visibility'], 'pas d\'archive, de dossier verrouillé ni de photos masquées');
        self::assertSame(['in' => ['IMAGE', 'VIDEO']], $body['filter']['type']);
    }

    public function testMemoriesOfTheDayMostRecentYearFirstWithoutEmptyOnes(): void
    {
        $memories = $this->client()->memories(self::BASE, new \DateTimeImmutable('2026-10-20'));

        self::assertCount(2, $memories);
        self::assertEquals(new ImmichMemory('mem-2023', 2023, [
            new ImmichAsset(self::P2, false, new \DateTimeImmutable('2023-10-20T14:00:00')),
            new ImmichAsset(self::P1, false, new \DateTimeImmutable('2023-10-20T15:00:00')),
        ]), $memories[0]);
        self::assertSame(2019, $memories[1]->year);
        parse_str((string) parse_url($this->requests[0][1], \PHP_URL_QUERY), $query);
        self::assertSame(['for' => '2026-10-20', 'type' => 'on_this_day'], $query);
    }

    public function testThumbnail(): void
    {
        $image = $this->client()->thumbnail(self::BASE, self::P1, 'preview');

        self::assertEquals(new CardImage('octets', 'image/webp'), $image);
        parse_str((string) parse_url($this->requests[0][1], \PHP_URL_QUERY), $query);
        self::assertSame(['size' => 'preview'], $query);
        self::assertSame(15.0, (float) $this->requests[0][2]['timeout']);
    }

    public function testThumbnailRejectsUnknownIdOrSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->thumbnail(self::BASE, '../server/config', 'thumbnail');
    }

    public function testRefusedKeyNamesThePermissions(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Clé Immich refusée (403)');

        $this->client(static fn () => new MockResponse('{}', ['http_code' => 403]))->statistics(self::BASE);
    }

    public function testMissingKey(): void
    {
        $this->expectExceptionMessage('IMMICH_API_KEY');

        $this->client(key: '')->statistics(self::BASE);
    }

    public function testServerErrorIsASourceError(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Immich injoignable');

        $this->client(static fn () => new MockResponse('oups', ['http_code' => 500]))->memories(self::BASE, new \DateTimeImmutable());
    }

    public function testUnreachable(): void
    {
        $this->expectExceptionMessage('Immich injoignable');

        $this->client(static fn () => new MockResponse('', ['error' => 'Connexion refusée']))->statistics(self::BASE);
    }
}
