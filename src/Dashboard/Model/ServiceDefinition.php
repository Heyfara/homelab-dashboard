<?php

namespace App\Dashboard\Model;

final readonly class ServiceDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description = null,
        /** Adresse ouverte par le navigateur */
        public ?string $url = null,
        /** Adresse testée par le serveur pour savoir si le service répond (sinon $url) */
        public ?string $check = null,
        public ?string $icon = null,
        public ?string $host = null,
        public ?string $container = null,
    ) {
    }
}
