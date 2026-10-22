<?php

namespace App\Tests\Source\Backblaze;

use App\Source\Backblaze\B2Bucket;
use App\Source\Backblaze\B2Client;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class B2ClientTest extends TestCase
{
    private const API = 'https://api.b2.test';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    /**
     * @param callable(string, array<string, string>): MockResponse $respond chemin + requête → réponse
     */
    private function client(callable $respond, string $keyId = 'kid', string $key = 'secret'): B2Client
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$method, $url, $options];
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return $respond((string) parse_url($url, \PHP_URL_PATH), $query);
        });

        return new B2Client($http, $keyId, $key, self::API);
    }

    private static function authorize(?string $bucketId = 'b1', ?string $bucketName = 'sauvegardes'): JsonMockResponse
    {
        return new JsonMockResponse([
            'accountId' => 'acc',
            'authorizationToken' => 'jeton',
            'apiInfo' => ['storageApi' => ['apiUrl' => 'https://api002.b2.test', 'bucketId' => $bucketId, 'bucketName' => $bucketName]],
        ]);
    }

    public function testKeyLimitedToOneBucket(): void
    {
        $client = $this->client(static fn (string $path, array $q) => match ($path) {
            '/b2api/v3/b2_authorize_account' => self::authorize(),
            '/b2api/v3/b2_list_buckets' => new JsonMockResponse(['buckets' => [['bucketId' => 'b1', 'bucketName' => $q['bucketName'], 'bucketType' => 'allPrivate']]]),
        });

        self::assertEquals([new B2Bucket('b1', 'sauvegardes', 'allPrivate')], $client->buckets());

        [, $url, $options] = $this->requests[0];
        self::assertSame(self::API.'/b2api/v3/b2_authorize_account', $url);
        self::assertContains('Authorization: Basic '.base64_encode('kid:secret'), $options['headers']);
        [, $url, $options] = $this->requests[1];
        self::assertStringStartsWith('https://api002.b2.test/b2api/v3/b2_list_buckets', $url);
        self::assertContains('Authorization: jeton', $options['headers']);
    }

    public function testKeyForTheWholeAccount(): void
    {
        $client = $this->client(static fn (string $path, array $q) => match ($path) {
            '/b2api/v3/b2_authorize_account' => self::authorize(null, null),
            '/b2api/v3/b2_list_buckets' => new JsonMockResponse(['buckets' => [
                ['bucketId' => 'b1', 'bucketName' => 'photos', 'bucketType' => 'allPrivate'],
                ['bucketId' => 'b2', 'bucketName' => 'sauvegardes', 'bucketType' => 'allPrivate'],
            ]]),
        });

        self::assertSame(['photos', 'sauvegardes'], array_map(static fn (B2Bucket $b) => $b->name, $client->buckets()));
        parse_str((string) parse_url($this->requests[1][1], \PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey('bucketName', $query);
    }

    public function testUsageSumsEveryStoredVersionAcrossPages(): void
    {
        $client = $this->client(static fn (string $path, array $q) => match (true) {
            '/b2api/v3/b2_authorize_account' === $path => self::authorize(),
            !isset($q['startFileName']) => new JsonMockResponse([
                'files' => [
                    ['action' => 'upload', 'contentLength' => 1000, 'uploadTimestamp' => 1792300000000],
                    ['action' => 'hide', 'contentLength' => 0, 'uploadTimestamp' => 1792400000000],
                ],
                'nextFileName' => 'data/b', 'nextFileId' => 'f2',
            ]),
            default => new JsonMockResponse([
                'files' => [['action' => 'upload', 'contentLength' => 500, 'uploadTimestamp' => 1792350000000]],
                'nextFileName' => null,
            ]),
        });

        $usage = $client->usage(new B2Bucket('b1', 'sauvegardes', 'allPrivate'));

        self::assertSame([1500, 2], [$usage->bytes, $usage->files]);
        self::assertEquals(new \DateTimeImmutable('@1792350000'), $usage->lastUpload, 'les marqueurs « hide » ne sont pas des envois');
        parse_str((string) parse_url(end($this->requests)[1], \PHP_URL_QUERY), $query);
        self::assertSame(['b1', '10000', 'data/b', 'f2'], [$query['bucketId'], $query['maxFileCount'], $query['startFileName'], $query['startFileId']]);
    }

    public function testEmptyBucket(): void
    {
        $client = $this->client(static fn (string $path) => '/b2api/v3/b2_authorize_account' === $path ? self::authorize() : new JsonMockResponse(['files' => [], 'nextFileName' => null]));

        $usage = $client->usage(new B2Bucket('b1', 'vide', 'allPrivate'));

        self::assertSame([0, 0, null], [$usage->bytes, $usage->files, $usage->lastUpload]);
    }

    public function testRejectedKey(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Clé Backblaze refusée (401), vérifier B2_KEY_ID et B2_APPLICATION_KEY');

        $this->client(static fn () => new MockResponse('{"code":"unauthorized"}', ['http_code' => 401]))->buckets();
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Backblaze injoignable/');

        $this->client(static fn () => new MockResponse('', ['error' => 'Could not resolve host']))->buckets();
    }

    public function testMissingKey(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Clé Backblaze absente (B2_KEY_ID, B2_APPLICATION_KEY)');

        $this->client(static fn () => throw new \LogicException('aucun appel attendu'), '', '')->buckets();
    }
}
