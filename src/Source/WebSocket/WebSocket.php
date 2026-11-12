<?php

namespace App\Source\WebSocket;

/** Une connexion WebSocket ouverte, échangeant des messages texte. */
interface WebSocket
{
    /** @throws WebSocketException */
    public function send(string $text): void;

    /**
     * Prochain message texte (les ping reçoivent leur pong en passant).
     *
     * @throws WebSocketException connexion fermée, coupée ou délai dépassé
     */
    public function receive(): string;

    public function close(): void;
}
