<?php

namespace App\Tests\Source\CalibreWeb;

use App\Card\Image\CardImage;
use App\Source\CalibreWeb\CalibreWebBook;
use App\Source\CalibreWeb\CalibreWebClient;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CalibreWebClientTest extends TestCase
{
    private const BASE = 'http://cwa:8083';

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    private function client(?callable $respond = null, string $username = 'lecteur', string $password = 'mdp'): CalibreWebClient
    {
        $respond ??= self::homelab(...);

        return new CalibreWebClient(new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$url, $options];
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return $respond((string) parse_url($url, \PHP_URL_PATH), $query);
        }), $username, $password);
    }

    /**
     * Réponses d'un Calibre-Web fictif, réutilisées par FakeHomelab.
     *
     * @param array<string, string> $query
     */
    public static function homelab(string $path, array $query = []): MockResponse
    {
        return match ($path) {
            '/opds/new' => new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/opds-new-'.(isset($query['offset']) ? 2 : 1).'.xml'), ['response_headers' => ['Content-Type' => 'application/atom+xml; charset=utf-8']]),
            '/opds/cover/12', '/opds/cover/3' => new MockResponse('octets', ['response_headers' => ['Content-Type' => 'image/webp']]),
            default => throw new \LogicException('Requête non prévue : '.$path),
        };
    }

    public function testRecentBooksFollowPagesUpToTheLimit(): void
    {
        $books = $this->client()->recent(self::BASE, 10);

        self::assertEquals([
            new CalibreWebBook(12, 'Le Phare des brumes', 'Jeanne Fictive, Paul Inventé', true, new \DateTimeImmutable('2026-10-18T20:35:19+00:00')),
            new CalibreWebBook(7, 'Sans couverture & sans auteur', null, false, new \DateTimeImmutable('2026-10-10T08:00:00+00:00')),
            new CalibreWebBook(3, 'Le Dernier Tome', 'Jeanne Fictive', true, new \DateTimeImmutable('2026-09-01T10:00:00+00:00')),
        ], $books);
        self::assertSame(self::BASE.'/opds/new?offset=2', $this->requests[1][0]);
        self::assertContains('Authorization: Basic '.base64_encode('lecteur:mdp'), $this->requests[0][1]['headers']);
    }

    public function testStopsAtTheLimit(): void
    {
        self::assertCount(1, $this->client()->recent(self::BASE, 1));
        self::assertCount(1, $this->requests, 'pas de page suivante inutile');
    }

    public function testCover(): void
    {
        self::assertEquals(new CardImage('octets', 'image/webp'), $this->client()->cover(self::BASE, 12));
        self::assertSame(self::BASE.'/opds/cover/12', $this->requests[0][0]);
        self::assertSame(15.0, (float) $this->requests[0][1]['timeout'], 'couvertures parfois lentes à générer');
    }

    public function testRefusedCredentials(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Identifiants Calibre-Web refusés (401)');

        $this->client(static fn () => new MockResponse('', ['http_code' => 401]))->recent(self::BASE, 5);
    }

    public function testMissingCredentials(): void
    {
        $this->expectExceptionMessage('CWA_USERNAME');

        $this->client(username: '')->recent(self::BASE, 5);
    }

    public function testLoginPageInsteadOfAFeed(): void
    {
        $this->expectExceptionMessage('n\'est pas un flux OPDS');

        $this->client(static fn () => new MockResponse('<!doctype html><html><body>Connexion</body></html>', ['response_headers' => ['Content-Type' => 'text/html']]))->recent(self::BASE, 5);
    }

    public function testUnreachable(): void
    {
        $this->expectExceptionMessage('Calibre-Web injoignable');

        $this->client(static fn () => new MockResponse('', ['error' => 'Connexion refusée']))->recent(self::BASE, 5);
    }
}
