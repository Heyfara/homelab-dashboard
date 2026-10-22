<?php

namespace App\Tests\Card\Type;

use App\Card\Type\DocFetcherCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\DocFetcher\DocFetcherStatusReader;
use App\Source\SourceUnavailableException;
use App\Tests\Source\DocFetcher\DocFetcherStatusReaderTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DocFetcherCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'papiers' => new ServiceDefinition('papiers', 'Papiers', url: 'https://papiers.example', icon: 'sh-paperless-ngx'),
        ], []);
    }

    /** « Maintenant » : deux jours après le dernier passage automatique, sauf mention. */
    private function card(string $now = '2026-10-21T09:00:00+02:00'): DocFetcherCard
    {
        return new DocFetcherCard(new DocFetcherStatusReader(), $now);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(DocFetcherCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('d', 'doc-fetcher', null, 2, 3600, $resolver->resolve(['file' => DocFetcherStatusReaderTest::FILE, ...$options]), 2);
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
        self::assertEquals(
            ['file' => DocFetcherStatusReaderTest::FILE, 'service' => null, 'labels' => [], 'stale_days' => 8, 'documents' => 6],
            $this->instance($this->card())->options,
        );

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'inconnu']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Imports Paperless'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testLastRunAndSchedule(): void
    {
        $data = $this->fetch();

        self::assertSame(['startedAt' => '2026-10-19T07:30:01+02:00', 'finishedAt' => '2026-10-19T07:31:12+02:00', 'state' => 'action', 'manual' => false], $data['lastRun']);
        self::assertSame('chaque lundi à 7 h 30', $data['schedule']);
        self::assertFalse($data['stale']);
    }

    public function testStaleWhenAutomaticRunsStop(): void
    {
        self::assertTrue($this->fetch(now: '2026-10-27T09:00:00+01:00')['stale'], 'cron pas passé depuis plus de 8 jours');
        self::assertFalse($this->fetch(['stale_days' => 10], '2026-10-27T09:00:00+01:00')['stale']);
    }

    public function testManualRunDoesNotHideStoppedCron(): void
    {
        // 2026-10-14 : passage manuel ; le dernier automatique date du 12.
        $data = $this->fetch(now: '2026-10-21T09:00:00+02:00', options: ['stale_days' => 7]);
        self::assertFalse($data['stale']);

        $reader = new DocFetcherStatusReader();
        $json = json_decode((string) file_get_contents(DocFetcherStatusReaderTest::FILE), true);
        array_shift($json['runs']);
        $file = tempnam(sys_get_temp_dir(), 'doc-fetcher');
        file_put_contents($file, json_encode($json, \JSON_THROW_ON_ERROR));

        $card = new DocFetcherCard($reader, '2026-10-21T09:00:00+02:00');
        $data = $card->fetch($this->instance($card, ['file' => $file, 'stale_days' => 7]), $this->dashboard);
        self::assertTrue($data['stale']);
        self::assertTrue($data['lastRun']['manual']);
    }

    public function testLatestStateOfEachSiteSortedByLabel(): void
    {
        self::assertSame([
            ['name' => 'impots', 'label' => 'Impôts', 'state' => 'action', 'checkedAt' => '2026-10-19T07:30:01+02:00', 'found' => null, 'missing' => null, 'errors' => ['appareil non reconnu, code mail requis : lancer run.sh --site impots']],
            ['name' => 'paie', 'label' => 'Paie', 'state' => 'ok', 'checkedAt' => '2026-10-19T07:30:01+02:00', 'found' => 12, 'missing' => 1, 'errors' => []],
        ], $this->fetch(['labels' => ['impots' => 'Impôts']])['sites']);
    }

    public function testHistoryOldestFirst(): void
    {
        self::assertSame([
            ['startedAt' => '2026-10-05T07:30:01+02:00', 'state' => 'ok', 'manual' => false, 'imported' => 0],
            ['startedAt' => '2026-10-12T07:30:01+02:00', 'state' => 'failed', 'manual' => false, 'imported' => 0],
            ['startedAt' => '2026-10-14T21:02:00+02:00', 'state' => 'ok', 'manual' => true, 'imported' => 2],
            ['startedAt' => '2026-10-19T07:30:01+02:00', 'state' => 'action', 'manual' => false, 'imported' => 1],
        ], $this->fetch()['history']);
    }

    public function testLatestDocumentsWithPaperlessLinks(): void
    {
        $data = $this->fetch(['service' => 'papiers', 'documents' => 2]);

        self::assertSame([
            ['id' => 412, 'title' => 'Bulletin septembre 2026', 'created' => '2026-09-30', 'site' => 'Paie', 'importedAt' => '2026-10-19T07:30:01+02:00', 'url' => 'https://papiers.example/documents/412/details'],
            ['id' => 405, 'title' => 'Avis d\'impôt 2026', 'created' => '2026-08-01', 'site' => 'Impots', 'importedAt' => '2026-10-14T21:02:00+02:00', 'url' => 'https://papiers.example/documents/405/details'],
        ], $data['documents']);
        self::assertSame(3, $data['importedTotal']);
    }

    public function testDocumentsWithoutServiceHaveNoLink(): void
    {
        self::assertNull($this->fetch()['documents'][0]['url']);
    }

    public function testMissingStatusFile(): void
    {
        $this->expectException(SourceUnavailableException::class);

        $this->fetch(['file' => '/nulle/part/status.json']);
    }
}
