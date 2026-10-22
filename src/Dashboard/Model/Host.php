<?php

namespace App\Dashboard\Model;

final readonly class Host
{
    public function __construct(
        public string $name,
        /** Nom du système dans Beszel (souvent avec une majuscule : « Alpha ») */
        public ?string $beszelSystem = null,
    ) {
    }
}
