<?php

namespace App\Tests\Card\Type;

use App\Card\Type\MachineCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Host;
use App\Dashboard\Model\Settings;
use App\Source\Beszel\BeszelClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MachineCardTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../fixtures/beszel';

    private Dashboard $dashboard;

    /** @var list<string> */
    private array $statsTypes = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [
            'alpha' => new Host('alpha', 'Alpha'),
            'beta' => new Host('beta', 'Beta'),
            'gamma' => new Host('gamma'),
        ], [], []);
    }

    private function card(): MachineCard
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

            return str_contains($query['filter'], 'sys-alpha')
                ? new MockResponse((string) file_get_contents(self::FIXTURES.'/stats-alpha.json'))
                : new JsonMockResponse(['items' => []]);
        });

        return new MachineCard(new BeszelClient($http, new ArrayAdapter(), 'http://beszel', 'u', 'p'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(MachineCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('m', 'machine', null, 2, 60, $resolver->resolve(['host' => 'alpha', ...$options]), 2);
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
            ['host' => 'alpha', 'period' => '24h', 'charts' => ['cpu', 'memory', 'network'], 'disk_names' => []],
            $this->instance($this->card())->options,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'machine sans Beszel' => [['host' => 'gamma']];
        yield 'machine inconnue' => [['host' => 'delta']];
        yield 'période' => [['period' => '2h']];
        yield 'graphique inconnu' => [['charts' => ['cpu', 'gpu']]];
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

        self::assertSame(['title' => 'alpha', 'system' => 'Alpha'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testIdentityAndCurrentState(): void
    {
        $data = $this->fetch();

        self::assertSame(['name' => 'Alpha', 'ip' => '10.0.0.2', 'status' => 'up', 'uptime' => 3826476, 'agent' => '0.21.0'], $data['machine']);
        self::assertSame(['cpu' => 3.39, 'memory' => 31.74, 'load' => [0.03, 0.13, 0.15], 'temperature' => 42], $data['now']);
        self::assertSame(['used' => 4.9, 'total' => 15.4], $data['memory'], 'Go, dernière mesure');
    }

    public function testDisksWithReadableNames(): void
    {
        $disks = $this->fetch(['disk_names' => ['media' => 'RAID']])['disks'];

        self::assertSame([
            ['name' => 'Système', 'percent' => 32.04, 'used' => 142.86, 'total' => 465.88],
            ['name' => 'RAID', 'percent' => 57.57, 'used' => 1055.15, 'total' => 1832.68],
        ], $disks);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function periods(): iterable
    {
        yield ['1h', '1m'];
        yield ['12h', '10m'];
        yield ['24h', '20m'];
        yield ['7d', '120m'];
        yield ['30d', '480m'];
    }

    #[DataProvider('periods')]
    public function testPeriodPicksBeszelResolution(string $period, string $type): void
    {
        $this->fetch(['period' => $period]);

        self::assertSame([$type], $this->statsTypes);
    }

    public function testSeriesOfRequestedChartsOnly(): void
    {
        $series = $this->fetch(['charts' => ['cpu', 'network', 'temperature']])['series'];

        self::assertSame(['2026-10-19T19:10:00+00:00', '2026-10-19T19:30:00+00:00'], $series['at']);
        self::assertSame([2.5, 3.53], $series['cpu']);
        self::assertSame([10000, 12974], $series['netSent']);
        self::assertSame([20000, 9631], $series['netReceived']);
        self::assertSame([41.3, 42.3], $series['temperature'], 'capteur le plus chaud');
        self::assertArrayNotHasKey('memory', $series);
    }

    public function testMachineDownStillShowsLastKnownState(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card, ['host' => 'beta']), $this->dashboard);

        self::assertSame('down', $data['machine']['status']);
        self::assertNull($data['now']['temperature']);
        self::assertSame([], $data['series']['at']);
        self::assertSame([['name' => 'Système', 'percent' => 2.94, 'used' => null, 'total' => null]], $data['disks']);
    }
}
