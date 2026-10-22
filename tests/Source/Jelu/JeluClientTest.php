<?php

namespace App\Tests\Source\Jelu;

use App\Source\Jelu\JeluClient;
use App\Source\Jelu\JeluReading;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JeluClientTest extends TestCase
{
    private const BASE = 'http://jelu:11111';

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    private function client(?callable $respond = null, string $token = 'jeton'): JeluClient
    {
        $respond ??= static fn (string $path, array $query) => self::homelab($path, $query);

        return new JeluClient(new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$url, $options];
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return $respond((string) parse_url($url, \PHP_URL_PATH), $query);
        }), $token);
    }

    /**
     * Jelu fictif, réutilisé par FakeHomelab.
     *
     * @param array<string, mixed> $query
     */
    public static function homelab(string $path, array $query = []): MockResponse
    {
        if ('/api/v1/reading-events' !== $path) {
            throw new \LogicException('Requête non prévue : '.$path);
        }
        $file = match ($query['eventTypes'] ?? null) {
            'CURRENTLY_READING' => 'jelu-reading.json',
            'FINISHED' => 'jelu-finished.json',
            default => throw new \LogicException('Type d\'événement non prévu : '.json_encode($query)),
        };

        return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/'.$file), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    public function testCurrentlyReadingForTheWholeFamily(): void
    {
        $reading = $this->client()->currentlyReading(self::BASE);

        self::assertEquals([
            new JeluReading('alice', 'b1', 'Le Phare des brumes', 'Jeanne Fictive', 'le-phare.jpg', 40, new \DateTimeImmutable('2026-10-10T20:00:00Z')),
            new JeluReading('bob', 'b2', 'La Forêt qui parle', 'Paul Inventé, Zoé Imaginaire', null, null, new \DateTimeImmutable('2026-10-15T20:00:00Z')),
        ], $reading);
        [$url, $options] = $this->requests[0];
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame(['eventTypes' => 'CURRENTLY_READING', 'size' => '200'], $query);
        self::assertContains('Authorization: Bearer jeton', $options['headers']);
        self::assertStringNotContainsString('HASH', serialize($reading), 'le DTO utilisateur de Jelu porte un champ password');
    }

    public function testFinishedSinceMostRecentFirst(): void
    {
        $finished = $this->client()->finishedSince(self::BASE, new \DateTimeImmutable('2026-01-01'));

        self::assertSame(['b3', 'b4', 'b5'], array_map(static fn (JeluReading $r): string => $r->bookId, $finished));
        self::assertEquals(new \DateTimeImmutable('2026-10-05T21:00:00Z'), $finished[0]->date, 'date de fin');
        parse_str((string) parse_url($this->requests[0][0], \PHP_URL_QUERY), $query);
        self::assertSame(['eventTypes' => 'FINISHED', 'endedAfter' => '2026-01-01', 'size' => '200', 'sort' => 'endDate,desc'], $query);
    }

    public function testRefusedToken(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Jeton Jelu refusé (403), vérifier JELU_API_TOKEN et son scope reading:read');

        $this->client(static fn () => new MockResponse('{}', ['http_code' => 403]))->currentlyReading(self::BASE);
    }

    public function testMissingToken(): void
    {
        $this->expectExceptionMessage('JELU_API_TOKEN');

        $this->client(token: '')->currentlyReading(self::BASE);
    }

    public function testUnreachable(): void
    {
        $this->expectExceptionMessage('Jelu injoignable');

        $this->client(static fn () => new MockResponse('', ['error' => 'Connexion refusée']))->currentlyReading(self::BASE);
    }
}
