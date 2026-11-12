<?php

namespace App\Source\WebSocket;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Connexions réelles, TLS vérifié avec l'autorité de TLS_CA_FILE (mkcert) si elle est donnée. */
final readonly class StreamWebSocketConnector implements WebSocketConnector
{
    public function __construct(
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
        private float $timeout = 5.0,
    ) {
    }

    public function open(string $url): WebSocket
    {
        return WebSocketConnection::connect($url, $this->caFile, $this->timeout);
    }
}
