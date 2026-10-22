<?php

namespace App\Tests\Card\Type;

use App\Card\Type\GithubPullRequestsCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use App\Source\Github\GithubClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class GithubPullRequestsCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
    }

    private function card(): GithubPullRequestsCard
    {
        return new GithubPullRequestsCard(new GithubClient(new MockHttpClient(new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/github-search.json'))), 'ghp_test'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(GithubPullRequestsCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('g', 'github-prs', null, 2, 300, $resolver->resolve(['repos' => ['exemple/infra', 'exemple/app'], ...$options]), 2);
    }

    public function testOptionsDefaults(): void
    {
        self::assertEquals(
            ['repos' => ['exemple/infra', 'exemple/app'], 'author' => null, 'labels' => [], 'limit' => 8, 'api' => 'https://api.github.com'],
            $this->instance($this->card())->options,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'aucun dépôt' => [['repos' => []]];
        yield 'dépôt mal écrit' => [['repos' => ['infra']]];
        yield 'injection dans la recherche' => [['repos' => ['exemple/app is:closed']]];
        yield 'limite' => [['limit' => 0]];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptions(array $options): void
    {
        $this->expectException(ExceptionInterface::class);

        $this->instance($this->card(), $options);
    }

    public function testSeveralReposLinkToTheSearch(): void
    {
        $card = $this->card();

        self::assertSame([
            'title' => 'Pull requests',
            'repos' => ['exemple/infra', 'exemple/app'],
            'url' => 'https://github.com/search?q=is%3Apr+is%3Aopen+archived%3Afalse+repo%3Aexemple%2Finfra+repo%3Aexemple%2Fapp+author%3Aapp%2Frenovate&type=pullrequests',
        ], $card->describe($this->instance($card, ['author' => 'app/renovate']), $this->dashboard));
    }

    public function testOneRepoLinksToItsPullRequestsPage(): void
    {
        $card = $this->card();

        self::assertSame('https://github.com/exemple/infra/pulls', $card->describe($this->instance($card, ['repos' => ['exemple/infra'], 'author' => 'app/renovate']), $this->dashboard)['url']);
    }

    public function testFetch(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card), $this->dashboard);

        self::assertSame(3, $data['total']);
        self::assertSame([
            'repo' => 'exemple/infra',
            'number' => 61,
            'title' => 'chore(deps): update ghcr.io/immich-app/immich-server to v2.4.0',
            'url' => 'https://github.com/exemple/infra/pull/61',
            'author' => 'renovate[bot]',
            'draft' => false,
            'labels' => [['name' => 'revue-requise', 'color' => '#d93f0b'], ['name' => 'backup-avant', 'color' => '#b60205']],
            'createdAt' => '2026-10-17T06:12:00+00:00',
            'updatedAt' => '2026-10-19T08:00:00+00:00',
        ], $data['items'][0]);
    }
}
