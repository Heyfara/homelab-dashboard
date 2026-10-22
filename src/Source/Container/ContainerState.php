<?php

namespace App\Source\Container;

final readonly class ContainerState
{
    public function __construct(
        /** running, restarting, paused ou stopped (arrêté ou supprimé) */
        public string $status,
        /** healthy, unhealthy, starting, ou null sans healthcheck */
        public ?string $health = null,
        /** Texte de Docker, en anglais : « Up 7 days » */
        public ?string $uptime = null,
        /** % d'un cœur */
        public ?float $cpu = null,
        /** Mo */
        public ?float $memory = null,
    ) {
    }

    public static function stopped(): self
    {
        return new self('stopped');
    }

    /**
     * @return array{status: string, health: ?string, uptime: ?string, cpu: ?float, memory: ?float}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'health' => $this->health, 'uptime' => $this->uptime, 'cpu' => $this->cpu, 'memory' => $this->memory];
    }
}
