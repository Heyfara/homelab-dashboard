<?php

namespace App\Tests\Card\Type;

use App\Card\Type\NetworkCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use App\Source\Freebox\FreeboxClient;
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

    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
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

        return new NetworkCard(new FreeboxClient($http, new ArrayAdapter(), 'http://box.test', 'jeton'), self::NOW);
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
        $card = new NetworkCard(new FreeboxClient($http, new ArrayAdapter(), 'http://box.test', 'jeton'), self::NOW);

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

        self::assertSame(['wan' => [
            'state' => 'up',
            'media' => 'ftth',
            'down' => 2_500_000,
            'up' => 40_000,
            'maxDown' => 125_000_000,
            'maxUp' => 62_500_000,
        ]], $card->fetchFeed('rates', $this->instance($card), $this->dashboard));
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
