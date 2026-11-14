<?php

namespace App\Dashboard\Model;

/**
 * Serveur ntfy dont le dashboard affiche les messages (cloche), et ses sujets.
 */
final readonly class Notifications
{
    /**
     * @param array<string, string> $topics sujet ntfy → nom affiché
     */
    public function __construct(
        /** Adresse de l'API, vue du serveur du dashboard */
        public string $url,
        /** Interface web de ntfy, pour le navigateur (facultatif) */
        public ?string $link,
        public array $topics,
    ) {
    }
}
