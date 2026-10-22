<?php

namespace App\Source\Github;

final readonly class GithubSearchResult
{
    /**
     * @param list<GithubPullRequest> $items les plus récemment mises à jour d'abord
     */
    public function __construct(
        /** Nombre total de PR correspondantes, au-delà de celles renvoyées */
        public int $total,
        public array $items,
    ) {
    }
}
