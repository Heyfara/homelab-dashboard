<?php

namespace App\Tests\Card\Type;

use App\Card\Type\StorageCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Host;
use App\Dashboard\Model\Settings;
use App\Source\Beszel\BeszelClient;
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

    /** Mesures renvoyées par Beszel pour sys-alpha : [date, Go utilisés, Go au total] */
    private array $points = [];

    /** Dernière mesure à la minute : [Go utilisés, Go au total] */
    private array $latest = [1055.15, 1832.68];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [
            'alpha' => new Host('alpha', 'Alpha'),
            'gamma' => new Host('gamma'),
        ], [], []);
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
            if (str_contains($url, '/systems/')) {
                return new MockResponse((string) file_get_contents(self::FIXTURES.'/systems.json'));
            }
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            preg_match("/type='(\\w+)'/", $query['filter'], $type);
            $this->statsTypes[] = $type[1];
            $points = '1m' === $type[1] ? [['2026-10-19 08:10:00.000Z', ...$this->latest]] : $this->points;

            return new JsonMockResponse(['items' => array_map(
                static fn (array $p): array => ['created' => $p[0], 'stats' => ['cpu' => 1, 'efs' => null === $p[1] ? [] : ['media' => ['d' => $p[2], 'du' => $p[1]]]]],
                $points,
            )]);
        });

        return new StorageCard(new BeszelClient($http, new ArrayAdapter(), 'http://beszel', 'u', 'p'));
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
        self::assertEquals(['host' => 'alpha', 'filesystem' => 'media', 'period' => '30d'], $this->instance($this->card())->options);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'machine sans Beszel' => [['host' => 'gamma']];
        yield 'machine inconnue' => [['host' => 'delta']];
        yield 'période trop courte pour une tendance' => [['period' => '24h']];
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

    public function testSeriesOfUsedSpace(): void
    {
        $series = $this->fetch()['series'];

        self::assertCount(11, $series['at']);
        self::assertSame('2026-10-09T08:00:00+00:00', $series['at'][0]);
        self::assertSame([1005.15, 1010.15], \array_slice($series['used'], 0, 2));
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

        self::assertNull($data['series']['used'][0]);
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

        self::assertNull($data['series']['used'][0], 'pas de faux creux sur la courbe');
        self::assertSame(9, $data['growth']['days']);
        self::assertEqualsWithDelta(5.0, $data['growth']['perDay'], 0.001);
    }

    public function testUnknownFilesystemListsTheKnownOnes(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Beszel ne suit pas « nas » sur Alpha (suivis : media)');

        $this->fetch(['filesystem' => 'nas']);
    }
}
