<?php

namespace App\Tests\Source\Http;

use App\Source\Http\RemoteFile;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RemoteFileTest extends TestCase
{
    /** @var list<array{string, string}> */
    private array $requests = [];

    private function file(MockResponse $response): RemoteFile
    {
        return new RemoteFile(new MockHttpClient(function (string $method, string $url) use ($response) {
            $this->requests[] = [$method, $url];

            return $response;
        }));
    }

    public function testLastModifiedFromAHeadRequest(): void
    {
        $date = $this->file(new MockResponse('', ['response_headers' => ['Last-Modified' => 'Tue, 20 Oct 2026 22:01:53 GMT']]))->lastModified('https://images.example/carte.png');

        self::assertEquals(new \DateTimeImmutable('2026-10-20T22:01:53+00:00'), $date);
        self::assertSame([['HEAD', 'https://images.example/carte.png']], $this->requests);
    }

    public function testWithoutHeader(): void
    {
        self::assertNull($this->file(new MockResponse(''))->lastModified('https://images.example/carte.png'));
    }

    public function testMissingFile(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Image introuvable (404) : https://images.example/carte.png');

        $this->file(new MockResponse('', ['http_code' => 404]))->lastModified('https://images.example/carte.png');
    }

    public function testUnreachable(): void
    {
        $this->expectExceptionMessage('injoignable');

        $this->file(new MockResponse('', ['error' => 'Connexion refusée']))->lastModified('https://images.example/carte.png');
    }
}
