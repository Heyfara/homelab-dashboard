<?php

namespace App\Tests\Card\Type;

use App\Card\Type\StorageCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Host;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Beszel\BeszelClient;
use App\Source\Healthchecks\HealthchecksClient;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class StorageCardTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../fixtures/beszel';

    private Dashboard $dashboard;

    /** @var list<string> */
    private array $statsTypes = [];

    /** @var list<string> */
    private array $healthchecksUrls = [];

    /** Mesures renvoyées par Beszel pour sys-alpha : [date, Go utilisés, Go au total] */
    private array $points = [];

    /** Dernière mesure à la minute : [Go utilisés, Go au total] */
    private array $latest = [1055.15, 1832.68];

    /** Pools btrfs/ZFS de la dernière minute (champ z de Beszel) */
    private array $pools = ['b:1234-abcd' => ['n' => 'tank', 'h' => 'ONLINE', 'hu' => true, 'd' => 1832.6, 'du' => 1055.1]];

    /** Disques suivis en SMART par Beszel (collection smart_devices) */
    private array $smart = [
        ['serial' => 'SN-A', 'model' => 'ST4000VN006-3CW104', 'capacity' => 4000787030016, 'state' => 'PASSED', 'temp' => 21, 'hours' => 192, 'attributes' => [
            ['id' => 5, 'rv' => 0], ['id' => 197, 'rv' => 0], ['id' => 198, 'rv' => 0], ['id' => 199, 'rv' => 0],
        ]],
        ['serial' => 'SN-B', 'model' => 'WDC WD20SPZX-08UA7', 'capacity' => 2000398934016, 'state' => 'PASSED', 'temp' => 19, 'hours' => 40215, 'attributes' => [
            ['id' => 5, 'rv' => 8], ['id' => 199, 'rv' => 0],
        ]],
        ['serial' => 'SN-SYS', 'model' => 'SSD système', 'capacity' => 500107862016, 'state' => 'PASSED', 'temp' => 23, 'hours' => 22340],
    ];

    /** Fiche du check de scrub dans Healthchecks ; null = check absent */
    private ?array $scrubCheck = ['status' => 'up', 'started' => false, 'last_ping' => '2026-11-01T17:01:08+01:00'];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [
            'alpha' => new Host('alpha', 'Alpha'),
            'gamma' => new Host('gamma'),
        ], [
            'healthchecks' => new ServiceDefinition('healthchecks', 'Healthchecks', url: 'https://healthchecks.example', check: 'http://10.0.0.2:8000'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
        // 10 jours, +5 Go par jour, 1832,68 Go au total.
        for ($day = 0; $day <= 10; ++$day) {
            $this->points[] = [\sprintf('2026-10-%02d 08:00:00.000Z', 9 + $day), 1005.15 + 5 * $day, 1832.68];
        }
    }

    private function card(): StorageCard
    {
        $http = new MockHttpClient(function (string $method, string $url) {
            if (str_contains($url, 'auth-with-password')) {
                return new JsonMockResponse(['token' => 't']);
            }
            if (str_contains($url, '/smart_devices/')) {
                return new JsonMockResponse(['items' => $this->smart]);
            }
            if (str_contains($url, '/systems/')) {
                return new MockResponse((string) file_get_contents(self::FIXTURES.'/systems.json'));
            }
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            preg_match("/type='(\\w+)'/", $query['filter'], $type);
            $this->statsTypes[] = $type[1];
            $points = '1m' === $type[1] ? [['2026-10-19 08:10:00.000Z', ...$this->latest]] : $this->points;

            $items = array_map(
                static fn (array $p): array => ['created' => $p[0], 'stats' => ['cpu' => 1, 'efs' => null === $p[1] ? [] : ['media' => ['d' => $p[2], 'du' => $p[1]]]]],
                $points,
            );
            if ('1m' === $type[1]) {
                $items[0]['stats']['z'] = $this->pools;
            }

            return new JsonMockResponse(['items' => $items]);
        });

        $healthchecks = new MockHttpClient(function (string $method, string $url) {
            $this->healthchecksUrls[] = $url;

            return new JsonMockResponse(['checks' => null === $this->scrubCheck ? [] : [['slug' => 'nas-scrub', ...$this->scrubCheck]]]);
        });

        return new StorageCard(new BeszelClient($http, new ArrayAdapter(), 'http://beszel', 'u', 'p'), new HealthchecksClient($healthchecks, 'cle'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(StorageCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('s', 'storage', null, 2, 300, $resolver->resolve(['host' => 'alpha', 'filesystem' => 'media', ...$options]), 1);
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
        self::assertEquals(['host' => 'alpha', 'filesystem' => 'media', 'period' => '30d', 'pool' => null, 'disks' => [], 'healthchecks' => null, 'scrub_check' => null], $this->instance($this->card())->options);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'machine sans Beszel' => [['host' => 'gamma']];
        yield 'machine inconnue' => [['host' => 'delta']];
        yield 'période trop courte pour une tendance' => [['period' => '24h']];
        yield 'scrub sans Healthchecks' => [['scrub_check' => 'nas-scrub']];
        yield 'Healthchecks sans adresse' => [['healthchecks' => 'sans-url', 'scrub_check' => 'nas-scrub']];
        yield 'Healthchecks inconnu' => [['healthchecks' => 'autre', 'scrub_check' => 'nas-scrub']];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptions(array $options): void
    {
        $this->expectException(ExceptionInterface::class);

        $this->instance($this->card(), $options);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'media', 'system' => 'Alpha'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testCurrentFilling(): void
    {
        $data = $this->fetch();

        self::assertSame(57.57, $data['percent'], 'pourcentage de Beszel');
        self::assertSame(1055.15, $data['used'], 'Go, dernière mesure à la minute');
        self::assertSame(1832.68, $data['total']);
        self::assertEqualsWithDelta(777.53, $data['free'], 0.001);
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function periods(): iterable
    {
        yield ['7d', '120m'];
        yield ['30d', '480m'];
    }

    #[DataProvider('periods')]
    public function testPeriodPicksBeszelResolution(string $period, string $type): void
    {
        $this->fetch(['period' => $period]);

        self::assertSame([$type, '1m'], $this->statsTypes);
    }

    public function testGrowthAndProjection(): void
    {
        $growth = $this->fetch()['growth'];

        self::assertEqualsWithDelta(5.0, $growth['perDay'], 0.001, 'Go par jour, droite des moindres carrés');
        self::assertEqualsWithDelta(155.5, $growth['fullInDays'], 0.01, '777,53 Go libres à 5 Go/j');
        self::assertSame(10, $growth['days'], 'recul de la tendance');
    }

    public function testNoProjectionWhenShrinking(): void
    {
        $this->points = array_map(static fn (array $p): array => [$p[0], 2000 - $p[1], $p[2]], $this->points);

        $growth = $this->fetch()['growth'];

        self::assertLessThan(0, $growth['perDay']);
        self::assertNull($growth['fullInDays']);
    }

    public function testNoTrendWithLessThanTwoDaysOfHistory(): void
    {
        $this->points = \array_slice($this->points, -2);

        self::assertNull($this->fetch()['growth']);
    }

    public function testMeasuresWithoutTheFilesystemAreGaps(): void
    {
        $this->points[0][1] = null;

        $data = $this->fetch();

        self::assertSame(9, $data['growth']['days'], 'la tendance part de la 1re mesure connue');
    }

    public function testCurrentValuesFromTheLatestMinute(): void
    {
        $this->latest = [1100.0, 1832.68];

        $data = $this->fetch();

        self::assertSame(1100.0, $data['used']);
        self::assertEqualsWithDelta(732.68, $data['free'], 0.001);
    }

    public function testPartialBucketsAreLeftOut(): void
    {
        // Volume apparu en cours de tranche : Beszel moyenne avec des zéros, d et du sont réduits d'autant.
        $this->points[0] = [$this->points[0][0], 300.0, 550.0];

        $data = $this->fetch();

        self::assertSame(9, $data['growth']['days'], 'tranche partielle ignorée');
        self::assertEqualsWithDelta(5.0, $data['growth']['perDay'], 0.001);
    }

    public function testNoPoolHealthUnlessAsked(): void
    {
        self::assertNull($this->fetch()['health']);
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function poolHealth(): iterable
    {
        yield ['ONLINE', 'ONLINE'];
        yield ['DEGRADED', 'DEGRADED'];
        yield ['UNKNOWN', 'UNKNOWN'];
    }

    #[DataProvider('poolHealth')]
    public function testPoolHealthByName(string $beszel, string $expected): void
    {
        $this->pools['b:1234-abcd']['h'] = $beszel;

        self::assertSame($expected, $this->fetch(['pool' => 'tank'])['health']);
    }

    public function testPoolMissingFromBeszelIsUnknown(): void
    {
        self::assertSame('UNKNOWN', $this->fetch(['pool' => 'autre'])['health']);
    }

    public function testUnknownFilesystemListsTheKnownOnes(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Beszel ne suit pas « nas » sur Alpha (suivis : media)');

        $this->fetch(['filesystem' => 'nas']);
    }

    public function testNoDisksNorScrubUnlessAsked(): void
    {
        $data = $this->fetch();

        self::assertSame([], $data['disks']);
        self::assertNull($data['scrub']);
        self::assertSame([], $this->healthchecksUrls);
    }

    public function testDisksInConfiguredOrder(): void
    {
        $disks = $this->fetch(['disks' => ['SN-B', 'SN-A']])['disks'];

        self::assertSame([
            ['serial' => 'SN-B', 'model' => 'WDC WD20SPZX-08UA7', 'capacity' => 2000398934016, 'state' => 'PASSED', 'temperature' => 19, 'hours' => 40215, 'counters' => ['reallocated' => 8, 'crc' => 0]],
            ['serial' => 'SN-A', 'model' => 'ST4000VN006-3CW104', 'capacity' => 4000787030016, 'state' => 'PASSED', 'temperature' => 21, 'hours' => 192, 'counters' => ['reallocated' => 0, 'pending' => 0, 'uncorrectable' => 0, 'crc' => 0]],
        ], $disks, 'le SSD système, non listé, est ignoré');
    }

    public function testDiskUnknownToBeszelIsMissing(): void
    {
        $disks = $this->fetch(['disks' => ['SN-A', 'SN-X']])['disks'];

        self::assertSame(['serial' => 'SN-X', 'model' => null, 'capacity' => null, 'state' => 'MISSING', 'temperature' => null, 'hours' => null, 'counters' => []], $disks[1]);
    }

    public function testLastScrubFromHealthchecks(): void
    {
        $scrub = $this->fetch(['healthchecks' => 'healthchecks', 'scrub_check' => 'nas-scrub'])['scrub'];

        self::assertSame(['status' => 'up', 'at' => '2026-11-01T17:01:08+01:00', 'running' => false], $scrub);
        self::assertStringStartsWith('http://10.0.0.2:8000/api/v3/checks/', $this->healthchecksUrls[0], 'adresse interne du service');
    }

    public function testScrubRunning(): void
    {
        $this->scrubCheck = ['status' => 'up', 'started' => true, 'last_ping' => '2026-12-01T10:00:02+01:00'];

        self::assertTrue($this->fetch(['healthchecks' => 'healthchecks', 'scrub_check' => 'nas-scrub'])['scrub']['running']);
    }

    public function testScrubUnavailableDoesNotHideTheCard(): void
    {
        $this->scrubCheck = null;

        $data = $this->fetch(['healthchecks' => 'healthchecks', 'scrub_check' => 'nas-scrub']);

        self::assertSame(['status' => 'unavailable', 'at' => null, 'running' => false], $data['scrub']);
        self::assertSame(57.57, $data['percent']);
    }
}
