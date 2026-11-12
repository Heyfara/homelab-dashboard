<?php

namespace App\Tests\Source\WebSocket;

use App\Source\WebSocket\WebSocketConnection;
use App\Source\WebSocket\WebSocketException;
use PHPUnit\Framework\TestCase;

/**
 * Le « serveur » est l'autre bout d'une paire de sockets locale : ses réponses
 * sont écrites d'avance, ce que le client envoie est relu après coup.
 */
final class WebSocketConnectionTest extends TestCase
{
    /** @var resource */
    private $client;

    /** @var resource */
    private $server;

    protected function setUp(): void
    {
        [$this->client, $this->server] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    }

    protected function tearDown(): void
    {
        foreach ([$this->client, $this->server] as $stream) {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** Réponse 101 du serveur, avec la clé attendue pour $key. */
    private static function accept(string $key, ?string $override = null): string
    {
        $accept = $override ?? base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        return "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n";
    }

    /** Trame serveur (jamais masquée). */
    private static function frame(string $payload, int $opcode = 0x1, bool $fin = true): string
    {
        $len = \strlen($payload);
        $head = \chr(($fin ? 0x80 : 0) | $opcode);
        if ($len < 126) {
            $head .= \chr($len);
        } elseif ($len < 65536) {
            $head .= \chr(126).pack('n', $len);
        } else {
            $head .= \chr(127).pack('J', $len);
        }

        return $head.$payload;
    }

    /** Décode les trames envoyées par le client (masquées), après l'en-tête HTTP. */
    private static function decodeClientFrames(string $bytes): array
    {
        $frames = [];
        $i = 0;
        while ($i < \strlen($bytes)) {
            $b0 = \ord($bytes[$i]);
            $b1 = \ord($bytes[$i + 1]);
            self::assertSame(0x80, $b1 & 0x80, 'trame client masquée (RFC 6455)');
            $len = $b1 & 0x7F;
            $i += 2;
            if (126 === $len) {
                $len = unpack('n', substr($bytes, $i, 2))[1];
                $i += 2;
            } elseif (127 === $len) {
                $len = unpack('J', substr($bytes, $i, 8))[1];
                $i += 8;
            }
            $mask = substr($bytes, $i, 4);
            $i += 4;
            $payload = substr($bytes, $i, $len) ^ str_repeat($mask, intdiv($len, 4) + 1);
            $i += $len;
            $frames[] = ['opcode' => $b0 & 0x0F, 'payload' => substr($payload, 0, $len)];
        }

        return $frames;
    }

    /** Ce que le client a écrit : [requête HTTP, trames]. */
    private function sent(): array
    {
        stream_set_blocking($this->server, false);
        $raw = stream_get_contents($this->server);
        [$http, $rest] = explode("\r\n\r\n", $raw, 2) + [1 => ''];

        return [$http, self::decodeClientFrames($rest)];
    }

    /**
     * Le client choisit sa clé au hasard : on la fixe pour pouvoir écrire la réponse d'avance.
     */
    private function open(string $serverBytes, string $key = 'dGhlIHNhbXBsZSBub25jZQ=='): WebSocketConnection
    {
        fwrite($this->server, $serverBytes);

        return WebSocketConnection::handshake($this->client, 'ha.example', '/api/websocket', $key, 2.0);
    }

    public function testHandshakeThenTextMessages(): void
    {
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $ws = $this->open(self::accept($key).self::frame('{"type":"auth_required"}'));

        self::assertSame('{"type":"auth_required"}', $ws->receive());
        $ws->send('{"type":"auth"}');

        [$http, $frames] = $this->sent();
        self::assertStringStartsWith("GET /api/websocket HTTP/1.1\r\n", $http);
        self::assertStringContainsString("Host: ha.example\r\n", $http);
        self::assertStringContainsString("Upgrade: websocket\r\n", $http);
        self::assertStringContainsString("Sec-WebSocket-Key: $key\r\n", $http);
        self::assertStringEndsWith("\r\nSec-WebSocket-Version: 13", $http);
        self::assertSame([['opcode' => 0x1, 'payload' => '{"type":"auth"}']], $frames);
    }

    public function testLongMessagesBothWays(): void
    {
        $big = str_repeat('é', 40000); // 80 000 octets : longueur sur 8 octets
        $mid = str_repeat('x', 300);   // longueur sur 2 octets
        $ws = $this->open(self::accept('dGhlIHNhbXBsZSBub25jZQ==').self::frame($big).self::frame($mid));

        self::assertSame($big, $ws->receive());
        self::assertSame($mid, $ws->receive());

        $ws->send($mid);
        self::assertSame($mid, $this->sent()[1][0]['payload']);
    }

    public function testFragmentedMessageIsReassembled(): void
    {
        $ws = $this->open(self::accept('dGhlIHNhbXBsZSBub25jZQ==').self::frame('{"a":', 0x1, false).self::frame('1', 0x0, false).self::frame('}', 0x0));

        self::assertSame('{"a":1}', $ws->receive());
    }

    public function testPingIsAnsweredAndSkipped(): void
    {
        $ws = $this->open(self::accept('dGhlIHNhbXBsZSBub25jZQ==').self::frame('coucou', 0x9).self::frame('après'));

        self::assertSame('après', $ws->receive());
        self::assertSame([['opcode' => 0xA, 'payload' => 'coucou']], $this->sent()[1]);
    }

    public function testCloseFromServerIsAnError(): void
    {
        $ws = $this->open(self::accept('dGhlIHNhbXBsZSBub25jZQ==').self::frame(pack('n', 1000).'bye', 0x8));

        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessageMatches('/fermé/');
        $ws->receive();
    }

    public function testRefusedUpgrade(): void
    {
        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessageMatches('/404/');

        $this->open("HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n");
    }

    public function testWrongAcceptKey(): void
    {
        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessageMatches('/Sec-WebSocket-Accept/');

        $this->open(self::accept('dGhlIHNhbXBsZSBub25jZQ==', 'faux'));
    }

    public function testServerSilenceTimesOut(): void
    {
        $ws = $this->open(self::accept('dGhlIHNhbXBsZSBub25jZQ=='));
        $ws->setTimeout(0.2);

        $this->expectException(WebSocketException::class);
        $this->expectExceptionMessageMatches('/délai/');
        $ws->receive();
    }

    public function testCloseSendsCloseFrame(): void
    {
        $ws = $this->open(self::accept('dGhlIHNhbXBsZSBub25jZQ=='));
        $ws->close();

        self::assertSame(0x8, $this->sent()[1][0]['opcode']);
    }
}
