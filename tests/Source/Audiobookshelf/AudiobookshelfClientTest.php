<?php

namespace App\Tests\Source\Audiobookshelf;

use App\Source\Audiobookshelf\AudiobookshelfBook;
use App\Source\Audiobookshelf\AudiobookshelfClient;
use App\Source\Audiobookshelf\AudiobookshelfProgress;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AudiobookshelfClientTest extends TestCase
{
    private const BASE = 'http://abs:80';

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    private function client(?callable $respond = null, string $key = 'cle'): AudiobookshelfClient
    {
        $respond ??= self::homelab(...);

        return new AudiobookshelfClient(new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$url, $options];
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return $respond((string) parse_url($url, \PHP_URL_PATH), $query);
        }), $key);
    }

    /**
     * @param array<string, string> $query
     */
    public static function homelab(string $path, array $query = []): MockResponse
    {
        $book = static fn (string $id, string $title, string $author, float $duration, int $addedAt) => ['id' => $id, 'addedAt' => $addedAt, 'media' => ['duration' => $duration, 'metadata' => ['title' => $title, 'authorName' => $author]]];

        return match ($path) {
            '/api/libraries' => new JsonMockResponse(['libraries' => [['id' => 'lib1', 'name' => 'Livres', 'mediaType' => 'book'], ['id' => 'pod', 'name' => 'Podcasts', 'mediaType' => 'podcast']]]),
            '/api/libraries/lib1/items' => new JsonMockResponse(['total' => 4, 'results' => [
                $book('b1', 'Astérix aux Jeux olympiques', 'Dominique Pinon', 5983.5, 1791391990770),
                $book('b2', 'Vingt mille lieues sous les mers', 'Jules Verne', 54089.4, 1791028843207),
            ]]),
            '/api/me' => new JsonMockResponse(['username' => 'root', 'mediaProgress' => [
                ['libraryItemId' => 'b2', 'progress' => 0.0055, 'currentTime' => 300, 'duration' => 54089, 'isFinished' => false, 'lastUpdate' => 1791041071456],
                ['libraryItemId' => 'b3', 'progress' => 0.73, 'currentTime' => 3019, 'duration' => 4125, 'isFinished' => false, 'lastUpdate' => 1791834884998],
                ['libraryItemId' => 'b1', 'progress' => 1, 'currentTime' => 5983, 'duration' => 5983, 'isFinished' => true, 'lastUpdate' => 1791900000000],
            ]]),
            '/api/users' => new JsonMockResponse(['users' => [['id' => 'u-root', 'username' => 'root'], ['id' => 'u-gab', 'username' => 'hugo']]]),
            '/api/users/u-gab' => new JsonMockResponse(['username' => 'hugo', 'mediaProgress' => [
                ['libraryItemId' => 'b1', 'progress' => 0.5, 'currentTime' => 2990, 'duration' => 5983, 'isFinished' => false, 'lastUpdate' => 1791950000000],
            ]]),
            '/api/items/b1', '/api/items/b2', '/api/items/b3' => new JsonMockResponse($book(substr($path, -2), 'Livre '.substr($path, -2), 'Auteur', 1000.0, 1790000000000)),
            default => throw new \LogicException('Requête non prévue : '.$path),
        };
    }

    public function testRecentBooksFromBookLibrariesOnly(): void
    {
        [$books, $total] = $this->client()->recent(self::BASE, 10);

        self::assertSame(4, $total);
        self::assertEquals(new AudiobookshelfBook('b1', 'Astérix aux Jeux olympiques', 'Dominique Pinon', 5984, new \DateTimeImmutable('@1791391990')), $books[0]);
        parse_str((string) parse_url($this->requests[1][0], \PHP_URL_QUERY), $query);
        self::assertSame(['addedAt', '1', '10'], [$query['sort'], $query['desc'], $query['limit']]);
        self::assertContains('Authorization: Bearer cle', $this->requests[0][1]['headers']);
        foreach ($this->requests as [$url]) {
            self::assertStringNotContainsString('/pod/', $url, 'les podcasts ne sont pas des livres');
        }
    }

    public function testProgressOfTheKeyOwnerMostRecentFirstWithoutFinished(): void
    {
        $progress = $this->client()->progress(self::BASE, null);

        self::assertEquals([
            new AudiobookshelfProgress('b3', 0.73, 3019, 4125, new \DateTimeImmutable('@1791834884')),
            new AudiobookshelfProgress('b2', 0.0055, 300, 54089, new \DateTimeImmutable('@1791041071')),
        ], $progress);
    }

    public function testProgressOfAnotherUser(): void
    {
        $progress = $this->client()->progress(self::BASE, 'Hugo');

        self::assertSame(['b1'], array_map(static fn (AudiobookshelfProgress $p) => $p->itemId, $progress));
    }

    public function testUnknownUser(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Utilisateur Audiobookshelf inconnu « lea » (utilisateurs : root, hugo)');

        $this->client()->progress(self::BASE, 'lea');
    }

    public function testBook(): void
    {
        self::assertSame('Livre b3', $this->client()->book(self::BASE, 'b3')->title);
        self::assertStringContainsString('expanded=1', $this->requests[0][0]);
    }

    public function testRejectedKey(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Clé Audiobookshelf refusée (401), vérifier AUDIOBOOKSHELF_API_KEY');

        $this->client(static fn () => new MockResponse('', ['http_code' => 401]))->recent(self::BASE, 5);
    }

    public function testMissingKey(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Clé Audiobookshelf absente (AUDIOBOOKSHELF_API_KEY)');

        $this->client(null, '')->recent(self::BASE, 5);
    }
}
