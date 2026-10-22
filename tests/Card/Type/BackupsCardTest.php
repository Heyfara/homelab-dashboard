<?php

namespace App\Tests\Card\Type;

use App\Card\Type\BackupsCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Backup\BackupStatusClient;
use App\Tests\Source\Backup\BackupStatusClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class BackupsCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'cuisine' => new ServiceDefinition('cuisine', 'Cuisine', url: 'https://cuisine.example', icon: 'sh-mealie'),
            'photos' => new ServiceDefinition('photos', 'Photos', url: 'https://photos.example', icon: 'sh-immich'),
        ], []);
    }

    /** « Maintenant » : 4 h après le passage de la sentinelle (08:00:01+02:00), sauf mention. */
    private function card(string $now = '2026-10-20T12:00:01+02:00'): BackupsCard
    {
        return new BackupsCard(new BackupStatusClient(new MockHttpClient(BackupStatusClientTest::homelab())), $now);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(BackupsCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('b', 'backups', null, 2, 600, $resolver->resolve(['url' => 'http://statut/backup-status.json', ...$options]), 2);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function fetch(array $options = [], ?string $now = null): array
    {
        $card = null === $now ? $this->card() : $this->card($now);

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptions(): void
    {
        self::assertEquals(['url' => 'http://statut/backup-status.json', 'aliases' => [], 'stale_hours' => 26], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['aliases' => ['galerie' => 'inconnu']]);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Sauvegardes'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testSummaryAndSentinelFreshness(): void
    {
        $data = $this->fetch();

        self::assertSame('2026-10-20T08:00:01+02:00', $data['checkedAt']);
        self::assertFalse($data['stale']);
        self::assertSame([2, 2, 4], [$data['ok'], $data['problems'], $data['total']]);

        self::assertTrue($this->fetch(now: '2026-10-21T11:00:01+02:00')['stale'], 'sentinelle pas repassée depuis 27 h');
    }

    public function testProblemsFirstThenOkByLabel(): void
    {
        self::assertSame(['notes', 'galerie', 'cuisine', 'restic'], array_column($this->fetch()['entries'], 'name'));
    }

    public function testLabelsSortedIgnoringCase(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'cuisine' => new ServiceDefinition('cuisine', 'zCuisine'),
            'restic' => new ServiceDefinition('restic', 'aRestic'),
        ], []);

        self::assertSame(['aRestic', 'zCuisine'], \array_slice(array_column($this->fetch()['entries'], 'label'), 2));
    }

    public function testLastResticRun(): void
    {
        self::assertSame([
            'finishedAt' => '2026-10-20T03:51:41+02:00',
            'durationSeconds' => 2200,
            'rc' => 0,
            'backupSeconds' => 2139,
            'dataAdded' => 110080000,
            'filesNew' => 493,
            'filesChanged' => 6101,
            'filesProcessed' => 66834,
            'bytesProcessed' => 471063000000,
            'copyRc' => 0,
            'copySeconds' => 28,
        ], $this->fetch()['restic']);
    }

    public function testNightsFromHistoryWithDisplayLabels(): void
    {
        self::assertSame([
            ['date' => '2026-10-18', 'problems' => []],
            ['date' => '2026-10-19', 'problems' => ['Photos']],
            ['date' => '2026-10-20', 'problems' => ['Notes', 'Photos']],
        ], $this->fetch(['aliases' => ['galerie' => 'photos']])['nights']);
    }

    public function testEntryWithCatalogServiceAndAgeAtDisplayTime(): void
    {
        $entries = $this->fetch(['aliases' => ['galerie' => 'photos']])['entries'];

        self::assertSame([
            'name' => 'cuisine',
            'label' => 'Cuisine',
            'state' => 'ok',
            'ageHours' => 9.0,
            'maxHours' => 30.0,
            'latest' => 'cuisine-20261020-030001.tar.gz',
            'reason' => null,
        ], $entries[2]);
        self::assertSame(['Photos', 56.0], [$entries[1]['label'], $entries[1]['ageHours']]);
        self::assertSame('Restic', $entries[3]['label'], 'hors catalogue : le nom de la sentinelle');
        self::assertNull($entries[0]['ageHours']);
    }
}
