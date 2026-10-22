<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Source\Github\GithubClient;
use App\Source\Github\GithubPullRequest;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Pull requests ouvertes d'un ou plusieurs dépôts GitHub, filtrées au besoin
 * par auteur et étiquettes. Pour les mises à jour Renovate :
 *
 *     - type: github-prs
 *       title: Mises à jour en attente
 *       repos: [exemple/infra, exemple/app]
 *       author: app/renovate
 *       span: 2
 *       rows: 2
 *       refresh: 300
 */
#[AsCard('github-prs')]
final readonly class GithubPullRequestsCard implements CardTypeInterface
{
    // Valeurs reprises telles quelles dans la recherche GitHub : rien qui puisse
    // y ajouter un critère (espace, guillemet).
    private const REPO = '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#';
    private const AUTHOR = '#^[A-Za-z0-9_.\[\]/-]+$#';

    public function __construct(
        private GithubClient $github,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $resolver
            ->setRequired('repos')
            ->setAllowedTypes('repos', 'string[]')
            ->setAllowedValues('repos', static fn (array $repos): bool => [] !== $repos && [] === array_filter($repos, static fn (string $r): bool => !preg_match(self::REPO, $r)))
            ->setInfo('repos', 'dépôts « propriétaire/nom »')
            ->setDefault('author', null)
            ->setAllowedTypes('author', ['null', 'string'])
            ->setAllowedValues('author', static fn (?string $a): bool => null === $a || 1 === preg_match(self::AUTHOR, $a))
            ->setInfo('author', 'ex. app/renovate')
            ->setDefault('labels', [])
            ->setAllowedTypes('labels', 'string[]')
            ->setAllowedValues('labels', static fn (array $labels): bool => [] === array_filter($labels, static fn (string $l): bool => '' === $l || str_contains($l, '"')))
            ->setDefault('limit', 8)
            ->setAllowedTypes('limit', 'int')
            ->setAllowedValues('limit', static fn (int $n): bool => $n >= 1 && $n <= 30)
            ->setDefault('api', 'https://api.github.com')
            ->setAllowedTypes('api', 'string')
            ->setInfo('api', 'API d\'une instance GitHub Enterprise, le cas échéant');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $o = $card->options;

        return [
            'title' => $card->title ?? 'Pull requests',
            'repos' => $o['repos'],
            // Un dépôt : sa page des pull requests ; plusieurs : la recherche, seule page qui les réunit.
            'url' => 1 === \count($o['repos'])
                ? \sprintf('https://github.com/%s/pulls', $o['repos'][0])
                : GithubClient::webSearchUrl($o['repos'], $o['author'], $o['labels']),
        ];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $o = $card->options;
        $result = $this->github->searchPullRequests($o['api'], $o['repos'], $o['author'], $o['labels'], $o['limit']);

        return [
            'total' => $result->total,
            'items' => array_map(static fn (GithubPullRequest $pr): array => [
                'repo' => $pr->repo,
                'number' => $pr->number,
                'title' => $pr->title,
                'url' => $pr->url,
                'author' => $pr->author,
                'draft' => $pr->draft,
                'labels' => $pr->labels,
                'createdAt' => $pr->createdAt->format(\DATE_ATOM),
                'updatedAt' => $pr->updatedAt->format(\DATE_ATOM),
            ], $result->items),
        ];
    }
}
