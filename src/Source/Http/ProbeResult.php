<?php

namespace App\Source\Http;

final readonly class ProbeResult
{
    public function __construct(
        public bool $up,
        public ?int $statusCode,
        public int $latencyMs,
        public ?string $error = null,
    ) {
    }

    /**
     * @return array{up: bool, statusCode: ?int, latencyMs: int, error: ?string}
     */
    public function toArray(): array
    {
        return ['up' => $this->up, 'statusCode' => $this->statusCode, 'latencyMs' => $this->latencyMs, 'error' => $this->error];
    }
}
