<?php

namespace App\Source\Ntfy;

/**
 * Un message publié dans ntfy par une application ou un script du homelab.
 */
final readonly class NtfyMessage
{
    /**
     * @param list<string> $tags étiquettes : émojis ntfy (warning, battery…) et mots-clés libres
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $time,
        public string $topic,
        public ?string $title,
        public string $message,
        /** 1 (min) à 5 (urgent) ; 3 par défaut */
        public int $priority,
        public array $tags,
        /** Adresse à ouvrir depuis le message */
        public ?string $click,
    ) {
    }
}
