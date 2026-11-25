<?php

namespace App\Tests\Card\Type;

use App\Card\Type\NetworkCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use App\Source\Freebox\FreeboxClient;
use App\Source\OpenWrt\OpenWrtProbe;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class NetworkCardTest extends TestCase
{
    private const NOW = '2026-11-24T12:00:00+00:00';

    private const FLINT = ['id' => 'flint', 'address' => '192.168.1.175', 'zone' => 'Bureau'];

    private Dashboard $dashboard;
    private string $dir;
    private float $time;
    /** Sortie du script dashboard-probe, null = Flint injoignable */
    private ?string $probe = null;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
        $this->dir = sys_get_temp_dir().'/network-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/ssh', 0700, true);
        touch($this->dir.'/ssh/id_ed25519');
        touch($this->dir.'/ssh/known_hosts');
        $this->time = (float) (new \DateTimeImmutable(self::NOW))->getTimestamp();
        $this->probe = self::probeOutput([[0, 0], [0, 0], [0, 0]]);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), array_merge(glob($this->dir.'/ssh/*') ?: [], glob($this->dir.'/data/*') ?: []));
        @rmdir($this->dir.'/data');
        rmdir($this->dir.'/ssh');
        rmdir($this->dir);
    }

    /**
     * Relevé du Flint : 00:…:01 câblé sur lan2, DA:…:05 en 5 GHz, 00:…:02 vu en amont.
     *
     * @param array{array{int, int}, array{int, int}, array{int, int}} $counters eth1, lan2, rax0
     */
    private static function probeOutput(array $counters): string
    {
        return (string) json_encode([
            'time' => 1,
            'ports' => ['eth1' => $counters[0], 'lan2' => $counters[1], 'rax0' => $counters[2]],
            'radios' => ['rax0' => 5.62],
            'fdb' => [['00:16:3E:00:00:01', 'lan2', 0.0], ['00:16:3E:00:00:02', 'eth1', 1.0]],
            'stations' => [['DA:A1:19:00:00:05', 'rax0', -41, 0]],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function host(string $mac, string $name, string $ip, bool $active, string $lastSeen, array $overrides = []): array
    {
        $last = (new \DateTimeImmutable($lastSeen))->getTimestamp();

        return array_replace([
            'l2ident' => ['id' => strtolower($mac), 'type' => 'mac_address'],
            'primary_name' => $name,
            'vendor_name' => 'Fabricant',
            'host_type' => 'workstation',
            'active' => $active,
            'last_activity' => $last,
            'l3connectivities' => [['addr' => $ip, 'af' => 'ipv4', 'active' => $active, 'last_activity' => $last]],
        ], $overrides);
    }

    private function card(bool $down = false): NetworkCard
    {
        $http = new MockHttpClient(static function (string $method, string $url) use ($down) {
            if ($down) {
                return new MockResponse('', ['error' => 'Connection refused']);
            }

            return match ((string) parse_url($url, \PHP_URL_PATH)) {
                '/api/v8/login/' => new JsonMockResponse(['success' => true, 'result' => ['challenge' => 'c']]),
                '/api/v8/login/session/' => new JsonMockResponse(['success' => true, 'result' => ['session_token' => 's']]),
                '/api/v8/lan/browser/pub/' => new JsonMockResponse(['success' => true, 'result' => [
                    self::host('00:16:3E:00:00:01', 'serveur', '192.168.1.2', true, '2026-11-24T11:59:00Z', ['host_type' => 'workstation']),
                    self::host('00:16:3E:00:00:02', 'imprimante', '192.168.1.30', true, '2026-11-24T11:58:00Z', ['host_type' => 'printer']),
                    self::host('00:16:3E:00:00:03', 'ancien-portable', '192.168.1.50', false, '2026-11-20T08:00:00Z', ['host_type' => 'laptop']),
                    self::host('00:16:3E:00:00:04', 'tres-ancien', '192.168.1.51', false, '2026-10-01T08:00:00Z'),
                    self::host('DA:A1:19:00:00:05', '', '192.168.1.60', true, '2026-11-24T11:59:30Z', ['vendor_name' => '', 'host_type' => 'other']),
                ]]),
                '/api/v8/dhcp/static_lease/' => new JsonMockResponse(['success' => true, 'result' => [
                    ['mac' => '00:16:3e:00:00:01', 'ip' => '192.168.1.2'],
                    ['mac' => '00:16:3e:00:00:02', 'ip' => '192.168.1.31'],
                ]]),
                '/api/v8/connection/' => new JsonMockResponse(['success' => true, 'result' => [
                    'state' => 'up', 'media' => 'ftth', 'rate_down' => 2_500_000, 'rate_up' => 40_000,
                    'bandwidth_down' => 1_000_000_000, 'bandwidth_up' => 500_000_000,
                ]]),
            };
        });

        return $this->network(new FreeboxClient($http, new ArrayAdapter(), 'http://box.test', 'jeton'));
    }

    private ?ArrayAdapter $cache = null;

    private function network(FreeboxClient $freebox): NetworkCard
    {
        $probe = new OpenWrtProbe($this->dir.'/ssh', fn (): array => null === $this->probe ? [255, '', 'Connection timed out'] : [0, $this->probe, ''], fn (): float => $this->time);
        $this->cache ??= new ArrayAdapter();

        return new NetworkCard($freebox, $probe, $this->cache, $this->dir.'/data', fn (): float => $this->time);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(NetworkCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('reseau', 'network', null, 4, 30, $resolver->resolve($options), 1);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Réseau'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testRatesFeedFollowsItsOwnRhythm(): void
    {
        $card = $this->card();

        self::assertSame(['rates' => 5], $card->feeds($this->instance($card)));
        self::assertSame(['rates' => 10], $card->feeds($this->instance($card, ['rates_refresh' => 10])));
    }

    public function testDevicesSeenRecentlyOnlineFirst(): void
    {
        $card = $this->card();

        $devices = $card->fetch($this->instance($card), $this->dashboard)['devices'];

        self::assertSame(['DA:A1:19:00:00:05', '00:16:3E:00:00:02', '00:16:3E:00:00:01', '00:16:3E:00:00:03'], array_column($devices, 'mac'));
        self::assertSame([
            'mac' => '00:16:3E:00:00:01',
            'name' => 'serveur',
            'vendor' => 'Fabricant',
            'type' => 'other',
            'ip' => '192.168.1.2',
            'expectedIp' => '192.168.1.2',
            'online' => true,
            'lastSeen' => '2026-11-24T11:59:00+00:00',
            'randomMac' => false,
            'place' => null,
        ], $devices[2]);
    }

    public function testUnnamedDeviceWithPrivateAddress(): void
    {
        $card = $this->card();

        $device = $card->fetch($this->instance($card), $this->dashboard)['devices'][0];

        self::assertNull($device['name']);
        self::assertNull($device['vendor']);
        self::assertSame('other', $device['type']);
        self::assertNull($device['expectedIp']);
        self::assertTrue($device['randomMac']);
    }

    public function testLeaseThatDoesNotMatchTheCurrentAddress(): void
    {
        $card = $this->card();

        $printer = $card->fetch($this->instance($card), $this->dashboard)['devices'][1];

        self::assertSame(['192.168.1.30', '192.168.1.31'], [$printer['ip'], $printer['expectedIp']]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function guessedTypes(): iterable
    {
        // « workstation » est le type par défaut de la Freebox : il ne dit rien.
        yield 'type par défaut, fabricant inconnu' => ['workstation', 'Fabricant', 'other'];
        yield 'type par défaut, carte mère de PC' => ['workstation', 'Giga-Byte Technology Co.,Ltd.', 'computer'];
        yield 'type par défaut, module ESP' => ['workstation', 'Espressif Inc.', 'iot'];
        yield 'type par défaut, point d’accès' => ['workstation', 'GL Technologies (Hong Kong) Limited', 'network'];
        yield 'type par défaut, station météo' => ['workstation', 'Netatmo', 'iot'];
        yield 'type précis de la Freebox' => ['printer', 'Espressif Inc.', 'printer'];
        yield 'Player Pop' => ['freebox_pop', 'Freebox Sas', 'tv'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guessedTypes')]
    public function testGuessedType(string $freeboxType, string $vendor, string $expected): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url) => match ((string) parse_url($url, \PHP_URL_PATH)) {
            '/api/v8/login/' => new JsonMockResponse(['success' => true, 'result' => ['challenge' => 'c']]),
            '/api/v8/login/session/' => new JsonMockResponse(['success' => true, 'result' => ['session_token' => 's']]),
            '/api/v8/lan/browser/pub/' => new JsonMockResponse(['success' => true, 'result' => [
                self::host('00:16:3E:00:00:01', 'appareil', '192.168.1.2', true, '2026-11-24T11:59:00Z', ['host_type' => $freeboxType, 'vendor_name' => $vendor]),
            ]]),
            '/api/v8/dhcp/static_lease/' => new JsonMockResponse(['success' => true]),
        });
        $card = $this->network(new FreeboxClient($http, new ArrayAdapter(), 'http://box.test', 'jeton'));

        self::assertSame($expected, $card->fetch($this->instance($card), $this->dashboard)['devices'][0]['type']);
    }

    public function testConfiguredNameAndTypeWin(): void
    {
        $card = $this->card();
        $instance = $this->instance($card, ['devices' => [
            '00:16:3e:00:00:01' => ['type' => 'server', 'name' => 'alpha'],
            'DA:A1:19:00:00:05' => ['type' => 'phone'],
        ]]);

        $devices = array_column($card->fetch($instance, $this->dashboard)['devices'], null, 'mac');

        self::assertSame(['alpha', 'server'], [$devices['00:16:3E:00:00:01']['name'], $devices['00:16:3E:00:00:01']['type']]);
        self::assertSame([null, 'phone'], [$devices['DA:A1:19:00:00:05']['name'], $devices['DA:A1:19:00:00:05']['type']]);
    }

    public function testForgetsDevicesAfterKeepDays(): void
    {
        $card = $this->card();

        $devices = $card->fetch($this->instance($card, ['keep_days' => 60]), $this->dashboard)['devices'];

        self::assertContains('00:16:3E:00:00:04', array_column($devices, 'mac'));
    }

    public function testRates(): void
    {
        $card = $this->card();

        self::assertSame(['accessPoints' => [], 'wan' => [
            'state' => 'up',
            'media' => 'ftth',
            'down' => 2_500_000,
            'up' => 40_000,
            'maxDown' => 125_000_000,
            'maxUp' => 62_500_000,
        ]], $card->fetchFeed('rates', $this->instance($card), $this->dashboard));
    }

    public function testPlacesDevicesFromTheAccessPoints(): void
    {
        $card = $this->card();
        $instance = $this->instance($card, ['access_points' => [self::FLINT]]);

        $data = $card->fetch($instance, $this->dashboard);
        $devices = array_column($data['devices'], 'place', 'mac');

        self::assertSame(['zone' => 'Bureau', 'ap' => 'flint', 'link' => 'cable', 'port' => 'lan2', 'band' => null, 'signal' => null, 'seenAt' => null], $devices['00:16:3E:00:00:01']);
        self::assertSame(['Bureau', 'wifi', '5', -41], [$devices['DA:A1:19:00:00:05']['zone'], $devices['DA:A1:19:00:00:05']['link'], $devices['DA:A1:19:00:00:05']['band'], $devices['DA:A1:19:00:00:05']['signal']]);
        self::assertSame('Baie', $devices['00:16:3E:00:00:02']['zone']);
        self::assertNull($devices['00:16:3E:00:00:03']);
        self::assertSame([['id' => 'flint', 'zone' => 'Bureau', 'error' => null, 'uplink' => 'eth1', 'radios' => ['rax0' => '5'], 'device' => null]], $data['accessPoints']);
        self::assertSame('Baie', $data['wiredZone']);
    }

    public function testAccessPointItselfIsFoundByItsAddress(): void
    {
        $card = $this->card();
        $instance = $this->instance($card, ['access_points' => [['address' => '192.168.1.30'] + self::FLINT], 'wired_zone' => 'Placard']);
        $this->probe = (string) json_encode(['time' => 1, 'ports' => [], 'radios' => [], 'fdb' => [], 'stations' => []]);

        $devices = array_column($card->fetch($instance, $this->dashboard)['devices'], 'place', 'mac');

        self::assertSame('Placard', $devices['00:16:3E:00:00:02']['zone']);
        self::assertSame('00:16:3E:00:00:02', $card->fetch($instance, $this->dashboard)['accessPoints'][0]['device']);
    }

    public function testRememberedPositionSurvivesAnUnreachableAccessPoint(): void
    {
        $card = $this->card();
        $instance = $this->instance($card, ['access_points' => [self::FLINT]]);
        $card->fetch($instance, $this->dashboard);

        $this->probe = null;
        $this->time += 60;
        $data = $card->fetch($instance, $this->dashboard);
        $devices = array_column($data['devices'], 'place', 'mac');

        self::assertSame(['Bureau', '2026-11-24T12:00:00+00:00'], [$devices['00:16:3E:00:00:01']['zone'], $devices['00:16:3E:00:00:01']['seenAt']]);
        self::assertSame('192.168.1.175 injoignable en SSH : Connection timed out', $data['accessPoints'][0]['error']);
    }

    public function testPortAndRadioRates(): void
    {
        $card = $this->card();
        $instance = $this->instance($card, ['access_points' => [self::FLINT]]);

        $first = $card->fetchFeed('rates', $instance, $this->dashboard);
        self::assertSame(['flint' => ['error' => null, 'ports' => []]], $first['accessPoints']);

        // 5 s plus tard. eth1 : 50 Mo reçus du switch (descendant), 5 Mo émis.
        $this->time += 5;
        $this->probe = self::probeOutput([[50_000_000, 5_000_000], [1_000_000, 10_000_000], [0, 2_500]]);
        $rates = $card->fetchFeed('rates', $instance, $this->dashboard)['accessPoints']['flint'];

        self::assertNull($rates['error']);
        self::assertSame(['down' => 10_000_000, 'up' => 1_000_000], $rates['ports']['eth1']);
        self::assertSame(['down' => 2_000_000, 'up' => 200_000], $rates['ports']['lan2']);
        self::assertSame(['down' => 500, 'up' => 0], $rates['ports']['rax0']);
    }

    public function testCounterResetGivesNoRate(): void
    {
        $card = $this->card();
        $instance = $this->instance($card, ['access_points' => [self::FLINT]]);
        $this->probe = self::probeOutput([[50, 50], [50, 50], [50, 50]]);
        $card->fetchFeed('rates', $instance, $this->dashboard);

        $this->time += 5;
        $this->probe = self::probeOutput([[10, 10], [60, 60], [50, 50]]);
        $ports = $card->fetchFeed('rates', $instance, $this->dashboard)['accessPoints']['flint']['ports'];

        self::assertSame(['lan2', 'rax0'], array_keys($ports));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'MAC invalide' => [['devices' => ['pas-une-mac' => ['type' => 'server']]]];
        yield 'type inconnu' => [['devices' => ['00:16:3E:00:00:01' => ['type' => 'grille-pain']]]];
        yield 'clé inconnue' => [['devices' => ['00:16:3E:00:00:01' => ['icone' => 'x']]]];
        yield 'débits trop fréquents' => [['rates_refresh' => 1]];
        yield 'durée nulle' => [['keep_days' => 0]];
        yield 'point d’accès sans adresse' => [['access_points' => [['id' => 'flint', 'zone' => 'Bureau']]]];
        yield 'point d’accès en double' => [['access_points' => [self::FLINT, self::FLINT]]];
        yield 'mémoire négative' => [['memory' => -1]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOptions')]
    public function testInvalidOptions(array $options): void
    {
        $this->expectException(ExceptionInterface::class);

        $this->instance($this->card(), $options);
    }

    public function testUnreachableBox(): void
    {
        $card = $this->card(down: true);

        $this->expectException(SourceUnavailableException::class);

        $card->fetch($this->instance($card), $this->dashboard);
    }
}
