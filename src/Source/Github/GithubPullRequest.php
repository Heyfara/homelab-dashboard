<?php

namespace App\Source\Github;

final readonly class GithubPullRequest
{
    /**
     * @param list<array{name: string, color: string}> $labels couleur en « #rrggbb »
     */
    public function __construct(
        /** « propriétaire/nom » */
        public string $repo,
        public int $number,
        public string $title,
        public string $url,
        public string $author,
        public bool $draft,
        public array $labels,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
