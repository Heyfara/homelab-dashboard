<?php

namespace App\Source\DocFetcher;

/** Un passage de doc-fetcher : tous les sites, ou un seul (run.sh --site). */
final readonly class DocFetcherRun
{
    /**
     * @param list<DocFetcherSite> $sites
     */
    public function __construct(
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        /** ok, failed, ou action (un site attend une intervention, code de double authentification) */
        public string $state,
        /** Lancé au terminal, pas par le cron */
        public bool $manual,
        public array $sites,
    ) {
    }
}
