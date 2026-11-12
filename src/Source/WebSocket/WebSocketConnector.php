<?php

namespace App\Source\WebSocket;

/** Ouvre des connexions WebSocket (simulé dans les tests). */
interface WebSocketConnector
{
    /** @throws WebSocketException */
    public function open(string $url): WebSocket;
}
