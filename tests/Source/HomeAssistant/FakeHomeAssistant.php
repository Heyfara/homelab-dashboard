<?php

namespace App\Tests\Source\HomeAssistant;

use App\Source\WebSocket\WebSocket;
use App\Source\WebSocket\WebSocketConnector;
use App\Source\WebSocket\WebSocketException;

/**
 * Faux Home Assistant pour les cartes : authentification acceptée, puis
 * chaque commande reçoit la réponse de $answer (commande décodée → résultat).
 * Les commandes reçues sont gardées dans $commands.
 */
final class FakeHomeAssistant implements WebSocketConnector
{
    /** @var list<array<string, mixed>> */
    public array $commands = [];

    /**
     * @param \Closure(array<string, mixed>): mixed $answer
     */
    public function __construct(private readonly \Closure $answer, private readonly bool $down = false)
    {
    }

    public function open(string $url): WebSocket
    {
        if ($this->down) {
            throw new WebSocketException('Connexion impossible à homeassistant:8123 (Connection refused)');
        }
        $fake = $this;

        return new class($fake) implements WebSocket {
            /** @var list<string> */
            private array $inbox = ['{"type":"auth_required","ha_version":"2026.11.1"}'];

            public function __construct(private readonly FakeHomeAssistant $fake)
            {
            }

            public function send(string $text): void
            {
                $message = json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
                $this->inbox[] = json_encode('auth' === $message['type']
                    ? ['type' => 'auth_ok']
                    : ['id' => $message['id'], 'type' => 'result', 'success' => true, 'result' => $this->fake->answer($message)], \JSON_THROW_ON_ERROR);
            }

            public function receive(): string
            {
                return array_shift($this->inbox) ?? throw new WebSocketException('Pas de réponse du serveur WebSocket dans le délai.');
            }

            public function close(): void
            {
            }
        };
    }

    /**
     * @param array<string, mixed> $command
     */
    public function answer(array $command): mixed
    {
        unset($command['id']);
        $this->commands[] = $command;

        return ($this->answer)($command);
    }
}
