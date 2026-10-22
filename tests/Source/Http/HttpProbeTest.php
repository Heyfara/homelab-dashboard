<?php

namespace App\Tests\Source\Http;

use App\Source\Http\HttpProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpProbeTest extends TestCase
{
    public function testServiceAnswers(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) {
            self::assertSame('GET', $method);
            self::assertSame('http://10.0.0.2:2283/', $url);
            self::assertSame(0, $options['max_redirects'], 'une redirection suffit à dire que le service répond');

            return new MockResponse('', ['http_code' => 302]);
        });

        $result = (new HttpProbe($http))->probe('http://10.0.0.2:2283');

        self::assertTrue($result->up);
        self::assertSame(302, $result->statusCode);
        self::assertNull($result->error);
        self::assertGreaterThanOrEqual(0, $result->latencyMs);
    }

    public function testClientErrorStillMeansUp(): void
    {
        $result = (new HttpProbe(new MockHttpClient(new MockResponse('', ['http_code' => 401]))))->probe('http://x');

        self::assertTrue($result->up);
    }

    public function testServerErrorMeansDown(): void
    {
        $result = (new HttpProbe(new MockHttpClient(new MockResponse('', ['http_code' => 502]))))->probe('http://x');

        self::assertFalse($result->up);
        self::assertSame(502, $result->statusCode);
    }

    public function testCustomCaFileIsUsed(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options) {
            self::assertSame('/certs/rootCA.pem', $options['cafile']);

            return new MockResponse('', ['http_code' => 200]);
        });

        self::assertTrue((new HttpProbe($http, '/certs/rootCA.pem'))->probe('https://photos.example')->up);
    }

    public function testNoAnswerMeansDown(): void
    {
        $result = (new HttpProbe(new MockHttpClient(new MockResponse('', ['error' => 'Connection timed out']))))->probe('http://x');

        self::assertFalse($result->up);
        self::assertNull($result->statusCode);
        self::assertStringContainsString('timed out', (string) $result->error);
    }
}
