<?php

namespace App\Tests\Source\Ntfy;

use App\Source\Ntfy\NtfyClient;
use App\Source\Ntfy\NtfyMessage;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NtfyClientTest extends TestCase
{
    private const URL = 'http://ntfy.test';

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    /**
     * Flux d'un serveur ntfy fictif (une ligne JSON par événement), tel que le
     * renvoie GET /<sujets>/json?poll=1.
     */
    public static function stream(): string
    {
        return implode("\n", array_map('json_encode', [
            ['id' => 'm1', 'time' => 1792400000, 'event' => 'message', 'topic' => 'maison', 'title' => 'Pile faible', 'message' => 'Thermomètre du salon (15 %)', 'priority' => 3, 'tags' => ['battery', 'homeassistant', 'pile']],
            ['id' => 'k1', 'time' => 1792400100, 'event' => 'keepalive', 'topic' => 'maison'],
            ['id' => 'm2', 'time' => 1792400200, 'event' => 'message', 'topic' => 'urgent', 'title' => 'Fumée détectée', 'message' => 'Salon', 'priority' => 5, 'tags' => ['rotating_light'], 'click' => 'https://maison.test/'],
            ['id' => 'm3', 'time' => 1792400300, 'event' => 'message', 'topic' => 'maison', 'message' => 'triggered'],
        ]))."\n";
    }

    private function client(?MockResponse $response = null, string $token = 'jeton-lecture'): NtfyClient
    {
        return new NtfyClient(new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = [$url, $options];

            return $response ?? new MockResponse(self::stream());
        }), $token);
    }

    public function testReadsTheMessagesOfTheTopicsNewestFirst(): void
    {
        $messages = $this->client()->messages(self::URL.'/', ['maison', 'urgent']);

        self::assertSame(['m3', 'm2', 'm1'], array_map(static fn (NtfyMessage $m): string => $m->id, $messages));
        self::assertEquals(new NtfyMessage('m2', new \DateTimeImmutable('@1792400200'), 'urgent', 'Fumée détectée', 'Salon', 5, ['rotating_light'], 'https://maison.test/'), $messages[1]);
        // Sans titre ni priorité : priorité par défaut de ntfy (3).
        self::assertEquals(new NtfyMessage('m3', new \DateTimeImmutable('@1792400300'), 'maison', null, 'triggered', 3, [], null), $messages[0]);
    }

    public function testPollsEverythingTheServerKeepsWithTheToken(): void
    {
        $this->client()->messages(self::URL, ['maison', 'urgent']);

        [$url, $options] = $this->requests[0];
        self::assertSame(self::URL.'/maison,urgent/json?poll=1&since=all', $url);
        self::assertContains('Authorization: Bearer jeton-lecture', $options['headers']);
    }

    public function testNoTopicMeansNoRequest(): void
    {
        self::assertSame([], $this->client()->messages(self::URL, []));
        self::assertSame([], $this->requests);
    }

    public function testMissingTokenIsReported(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('NTFY_TOKEN');

        $this->client(token: '')->messages(self::URL, ['maison']);
    }

    public function testRefusedTokenIsReported(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Jeton ntfy refusé (403)');

        $this->client(new MockResponse('{"code":40301}', ['http_code' => 403]))->messages(self::URL, ['maison']);
    }

    public function testUnreachableServerIsReported(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('ntfy injoignable');

        $this->client(new MockResponse('', ['error' => 'Connection refused']))->messages(self::URL, ['maison']);
    }
}
