<?php

namespace App\Tests\Card\Type;

use App\Card\Type\BackblazeCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use App\Source\Backblaze\B2Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class BackblazeCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
    }

    private function card(): BackblazeCard
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

        return new BackblazeCard(new B2Client($http, 'kid', 'secret', 'https://api.b2.test'));
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
    private function fetch(array $options = []): array
    {
        $card = $this->card();

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptionsDefaults(): void
    {
        self::assertEquals(
            ['buckets' => [], 'price_per_tb' => 6.95, 'free_gb' => 10, 'currency' => '$', 'vat' => 0, 'stale_hours' => 36],
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
        self::assertSame(['monthly' => 2.71, 'monthlyExclVat' => 2.71, 'vat' => 0.0, 'pricePerTb' => 6.95, 'freeGb' => 10.0, 'currency' => '$'], $this->fetch()['cost']);
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
}
