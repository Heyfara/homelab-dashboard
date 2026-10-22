<?php

namespace App\Source\DocFetcher;

/** Statut écrit par doc-fetcher (rôle Ansible doc-fetcher) à chaque passage. */
final readonly class DocFetcherStatus
{
    /**
     * @param list<DocFetcherRun> $runs le plus récent d'abord
     */
    public function __construct(
        /** Planification lisible (« chaque lundi à 7 h 30 »), si le script la connaît */
        public ?string $schedule,
        public array $runs,
    ) {
    }
}
