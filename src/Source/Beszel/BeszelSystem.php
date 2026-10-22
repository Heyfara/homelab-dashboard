<?php

namespace App\Source\Beszel;

final readonly class BeszelSystem
{
    /**
     * @param array<string, mixed> $info état actuel envoyé par l'agent (clés abrégées de
     *                                   Beszel : cpu, mp, dp, u, la, dt, efs, v…)
     */
    public function __construct(
        public string $id,
        public string $name,
        /** up, down, paused ou pending */
        public string $status,
        /** Adresse de l'agent (IP) */
        public string $host = '',
        public array $info = [],
    ) {
    }
}
