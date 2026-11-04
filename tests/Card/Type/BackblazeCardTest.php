<?php

namespace App\Tests\Card\Type;

use App\Card\Type\BackblazeCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use App\History\DailyHistory;
use App\Source\Backblaze\B2Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Yaml\Yaml;

final class BackblazeCardTest extends TestCase
{
    private Dashboard $dashboard;
    private string $dir;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
        $this->dir = sys_get_temp_dir().'/b2-history-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', \GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    private function history(string $now = '2026-11-04T10:00:00+01:00'): DailyHistory
    {
        return new DailyHistory($this->dir, $now);
    }

    private function card(string $now = '2026-11-04T10:00:00+01:00'): BackblazeCard
    {
        $http = new MockHttpClient(static function (string $method, string $url) {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);

            return match (true) {
                str_contains($url, 'b2_authorize_account') => new JsonMockResponse(['accountId' => 'acc', 'authorizationToken' => 't', 'apiInfo' => ['storageApi' => ['apiUrl' => 'https://api002.b2.test', 'bucketId' => null, 'bucketName' => null]]]),
                str_contains($url, 'b2_list_buckets') => new JsonMockResponse(['buckets' => [
                    ['bucketId' => 'b1', 'bucketName' => 'sauvegardes', 'bucketType' => 'allPrivate'],
                    ['bucketId' => 'b2', 'bucketName' => 'archives', 'bucketType' => 'allPrivate'],
                ]]),
                'b1' === $q['bucketId'] => new JsonMockResponse(['files' => [['action' => 'upload', 'contentLength' => 398_400_000_000, 'uploadTimestamp' => 1792400000000]], 'nextFileName' => null]),
                default => new JsonMockResponse(['files' => [['action' => 'upload', 'contentLength' => 1_600_000_000, 'uploadTimestamp' => 1780000000000]], 'nextFileName' => null]),
            };
        });

        return new BackblazeCard(new B2Client($http, 'kid', 'secret', 'https://api.b2.test'), $this->history($now), $now);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(BackblazeCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('b', 'backblaze', null, 2, 86400, $resolver->resolve($options), 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(array $options = [], string $now = '2026-11-04T10:00:00+01:00'): array
    {
        $card = $this->card($now);

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptionsDefaults(): void
    {
        self::assertEquals(
            ['buckets' => [], 'price_per_tb' => ['2023-10-03' => 6.0, '2026-10-01' => 6.95], 'free_gb' => 10, 'currency' => '$', 'vat' => 0, 'stale_hours' => 36, 'history_days' => 365],
            $this->instance($this->card())->options,
        );

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['price_per_tb' => -1]);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(
            ['title' => 'Backblaze B2', 'url' => 'https://secure.backblaze.com/b2_buckets.htm', 'staleHours' => 36],
            $card->describe($this->instance($card), $this->dashboard),
        );
    }

    public function testEveryVisibleBucketByDefault(): void
    {
        $data = $this->fetch();

        self::assertSame([
            ['name' => 'sauvegardes', 'bytes' => 398_400_000_000, 'files' => 1, 'lastUpload' => '2026-10-19T08:53:20+00:00'],
            ['name' => 'archives', 'bytes' => 1_600_000_000, 'files' => 1, 'lastUpload' => '2026-05-28T20:26:40+00:00'],
        ], $data['buckets']);
        self::assertSame(['bytes' => 400_000_000_000, 'files' => 2], $data['total']);
    }

    public function testEstimatedMonthlyCost(): void
    {
        // (400 Go − 10 Go offerts) / 1000 × 6,95 $
        self::assertSame([
            'monthly' => 2.71, 'monthlyExclVat' => 2.71, 'vat' => 0.0, 'pricePerTb' => 6.95, 'freeGb' => 10.0, 'currency' => '$',
            'prices' => [['from' => '2023-10-03', 'pricePerTb' => 6.0], ['from' => '2026-10-01', 'pricePerTb' => 6.95]],
        ], $this->fetch()['cost']);
        self::assertSame(0.0, $this->fetch(['free_gb' => 500])['cost']['monthly']);
    }

    public function testVatAddedToTheEstimate(): void
    {
        $cost = $this->fetch(['vat' => 20])['cost'];

        // 2,7105 $ HT × 1,20
        self::assertSame([3.25, 2.71, 20.0], [$cost['monthly'], $cost['monthlyExclVat'], $cost['vat']]);
    }

    public function testOnlyChosenBuckets(): void
    {
        self::assertSame(['archives'], array_column($this->fetch(['buckets' => ['archives']])['buckets'], 'name'));
    }

    public function testSinglePriceStillAccepted(): void
    {
        $cost = $this->fetch(['price_per_tb' => 7])['cost'];

        self::assertSame([7.0, [['from' => '1970-01-01', 'pricePerTb' => 7.0]]], [$cost['pricePerTb'], $cost['prices']]);
    }

    public function testDatedPricesWrittenAsYamlBlock(): void
    {
        // En bloc, Symfony Yaml lit une clé date comme un timestamp.
        $options = Yaml::parse("price_per_tb:\n  2026-10-01: 6.95\n  2023-10-03: 6\n");

        self::assertSame(['2023-10-03' => 6.0, '2026-10-01' => 6.95], $this->instance($this->card(), $options)->options['price_per_tb']);
    }

    public function testInvalidDatedPrices(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['price_per_tb' => ['1er octobre' => 6.95]]);
    }

    public function testPriceInForceOnTheDay(): void
    {
        // Avant la hausse du 2026-10-01 : (400 − 10) / 1000 × 6 $
        $cost = $this->fetch(now: '2026-09-15T10:00:00+02:00')['cost'];

        self::assertSame([6.0, 2.34], [$cost['pricePerTb'], $cost['monthly']]);
    }

    public function testDailySizeRecordedPerBucket(): void
    {
        $this->fetch();

        self::assertSame(['2026-11-04' => ['sauvegardes' => 398_400_000_000, 'archives' => 1_600_000_000]], $this->history()->read('backblaze'));
    }

    public function testHistoryOfCostWithThePriceOfEachDay(): void
    {
        $this->history('2026-09-30T10:00:00+02:00')->record('backblaze', ['sauvegardes' => 300_000_000_000, 'archives' => 10_000_000_000]);
        $this->history('2026-10-01T10:00:00+02:00')->record('backblaze', ['sauvegardes' => 300_000_000_000, 'archives' => 10_000_000_000]);

        self::assertSame([
            // (310 − 10) / 1000 × 6 $, puis × 6,95 $ le jour de la hausse
            ['date' => '2026-09-30', 'bytes' => 310_000_000_000, 'monthly' => 1.8, 'pricePerTb' => 6.0],
            ['date' => '2026-10-01', 'bytes' => 310_000_000_000, 'monthly' => 2.09, 'pricePerTb' => 6.95],
            ['date' => '2026-11-04', 'bytes' => 400_000_000_000, 'monthly' => 2.71, 'pricePerTb' => 6.95],
        ], $this->fetch()['history']);
    }

    public function testHistoryOnlyOfChosenBucketsWithVatAndWithinHistoryDays(): void
    {
        $this->history('2025-11-03T10:00:00+01:00')->record('backblaze', ['archives' => 50_000_000_000]);
        $this->history('2026-10-20T10:00:00+02:00')->record('backblaze', ['sauvegardes' => 300_000_000_000]);
        $this->history('2026-10-21T10:00:00+02:00')->record('backblaze', ['archives' => 20_000_000_000]);

        self::assertSame([
            // (20 − 10) / 1000 × 6,95 $ × 1,20 ; le 2026-10-20 n'a pas de relevé d'archives, le 2025-11-03 est trop vieux
            ['date' => '2026-10-21', 'bytes' => 20_000_000_000, 'monthly' => 0.08, 'pricePerTb' => 6.95],
            ['date' => '2026-11-04', 'bytes' => 1_600_000_000, 'monthly' => 0.0, 'pricePerTb' => 6.95],
        ], $this->fetch(['buckets' => ['archives'], 'vat' => 20])['history']);
    }
}
