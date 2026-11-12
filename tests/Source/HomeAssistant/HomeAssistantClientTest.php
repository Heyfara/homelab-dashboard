<?php

namespace App\Tests\Source\HomeAssistant;

use App\Source\HomeAssistant\HomeAssistantClient;
use App\Source\SourceUnavailableException;
use App\Source\WebSocket\WebSocket;
use App\Source\WebSocket\WebSocketConnector;
use App\Source\WebSocket\WebSocketException;
use PHPUnit\Framework\TestCase;

final class HomeAssistantClientTest extends TestCase
{
    /**
     * Faux Home Assistant : $reply reçoit chaque message envoyé (décodé) et
     * renvoie les messages à mettre en file en réponse.
     *
     * @param callable(array<string, mixed>): list<array<string, mixed>> $reply
     */
    private static function homeAssistant(callable $reply, ?\Throwable $openError = null): object
    {
        return new class($reply, $openError) implements WebSocketConnector {
            public ?string $url = null;

            /** @var list<array<string, mixed>> */
            public array $sent = [];

            public bool $closed = false;

            public function __construct(private $reply, private ?\Throwable $openError)
            {
            }

            public function open(string $url): WebSocket
            {
                $this->url = $url;
                if (null !== $this->openError) {
                    throw $this->openError;
                }
                $server = $this;

                return new class($server) implements WebSocket {
                    /** @var list<string> */
                    private array $inbox = ['{"type":"auth_required","ha_version":"2026.11.1"}'];

                    public function __construct(private object $server)
                    {
                    }

                    public function send(string $text): void
                    {
                        $message = json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
                        $this->server->sent[] = $message;
                        foreach ($this->server->respond($message) as $answer) {
                            $this->inbox[] = json_encode($answer, \JSON_THROW_ON_ERROR);
                        }
                    }

                    public function receive(): string
                    {
                        return array_shift($this->inbox) ?? throw new WebSocketException('Pas de réponse du serveur WebSocket dans le délai.');
                    }

                    public function close(): void
                    {
                        $this->server->closed = true;
                    }
                };
            }

            public function respond(array $message): array
            {
                return ($this->reply)($message);
            }
        };
    }

    /** Authentification acceptée, puis $results par type de commande. */
    private static function acceptingWith(array $results): callable
    {
        return static function (array $m) use ($results): array {
            if ('auth' === $m['type']) {
                return 'jeton' === $m['access_token'] ? [['type' => 'auth_ok', 'ha_version' => '2026.11.1']] : [['type' => 'auth_invalid', 'message' => 'Invalid access token or password']];
            }
            $r = $results[$m['type']] ?? ['success' => false, 'error' => ['code' => 'unknown_command', 'message' => 'Unknown command.']];

            return [['id' => $m['id'], 'type' => 'result', ...$r]];
        };
    }

    public function testAuthenticatesThenAnswersEachCommandByKey(): void
    {
        $ha = self::homeAssistant(self::acceptingWith([
            'energy/get_prefs' => ['success' => true, 'result' => ['energy_sources' => []]],
            'get_states' => ['success' => true, 'result' => [['entity_id' => 'sensor.a', 'state' => '5']]],
        ]));

        $results = (new HomeAssistantClient($ha, 'jeton'))->query('https://maison.example', [
            'prefs' => ['type' => 'energy/get_prefs'],
            'states' => ['type' => 'get_states'],
        ]);

        self::assertSame('wss://maison.example/api/websocket', $ha->url);
        self::assertSame(['type' => 'auth', 'access_token' => 'jeton'], $ha->sent[0]);
        self::assertSame([1, 2], array_column(\array_slice($ha->sent, 1), 'id'), 'un identifiant par commande');
        self::assertSame(['energy_sources' => []], $results['prefs']);
        self::assertSame([['entity_id' => 'sensor.a', 'state' => '5']], $results['states']);
        self::assertTrue($ha->closed);
    }

    public function testPlainHttpGivesPlainWebSocket(): void
    {
        $ha = self::homeAssistant(self::acceptingWith(['get_config' => ['success' => true, 'result' => []]]));

        (new HomeAssistantClient($ha, 'jeton'))->query('http://10.0.0.2:8123/', ['c' => ['type' => 'get_config']]);

        self::assertSame('ws://10.0.0.2:8123/api/websocket', $ha->url);
    }

    public function testRefusedToken(): void
    {
        $ha = self::homeAssistant(self::acceptingWith([]));

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/jeton.*refusé/i');

        try {
            (new HomeAssistantClient($ha, 'faux'))->query('https://maison.example', ['c' => ['type' => 'get_config']]);
        } finally {
            self::assertTrue($ha->closed, 'connexion fermée même en cas d’échec');
        }
    }

    public function testMissingToken(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/HOME_ASSISTANT_TOKEN/');

        (new HomeAssistantClient(self::homeAssistant(self::acceptingWith([])), ''))->query('https://maison.example', ['c' => ['type' => 'get_config']]);
    }

    public function testFailedCommandNamesItAndHintsAtAdminRights(): void
    {
        $ha = self::homeAssistant(self::acceptingWith([
            'energy/get_prefs' => ['success' => false, 'error' => ['code' => 'unauthorized', 'message' => 'Unauthorized']],
        ]));

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/energy\/get_prefs.*Unauthorized.*administrateur/s');

        (new HomeAssistantClient($ha, 'jeton'))->query('https://maison.example', ['p' => ['type' => 'energy/get_prefs']]);
    }

    public function testEventsBetweenResultsAreIgnored(): void
    {
        $ha = self::homeAssistant(static function (array $m): array {
            if ('auth' === $m['type']) {
                return [['type' => 'auth_ok']];
            }

            return [['id' => 99, 'type' => 'event', 'event' => []], ['id' => $m['id'], 'type' => 'result', 'success' => true, 'result' => 'ok']];
        });

        self::assertSame(['c' => 'ok'], (new HomeAssistantClient($ha, 'jeton'))->query('https://maison.example', ['c' => ['type' => 'get_config']]));
    }

    public function testUnreachable(): void
    {
        $ha = self::homeAssistant(self::acceptingWith([]), new WebSocketException('Connexion impossible à maison.example:443 (Connection refused)'));

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Home Assistant injoignable.*refused/');

        (new HomeAssistantClient($ha, 'jeton'))->query('https://maison.example', ['c' => ['type' => 'get_config']]);
    }
}
