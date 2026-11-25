<?php

namespace App\Tests\Network;

use App\Network\AccessPoint;
use App\Network\Placement;
use App\Source\OpenWrt\OpenWrtSnapshot;
use PHPUnit\Framework\TestCase;

final class PlacementTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const PC = '00:16:3E:00:00:01';
    private const PHONE = '00:16:3E:00:00:02';
    private const SERVER = '00:16:3E:00:00:03';
    private const TABLET = '00:16:3E:00:00:04';
    private const FLINT = '00:16:3E:00:00:AA';

    /** @var array<string, AccessPoint> */
    private array $aps;

    protected function setUp(): void
    {
        $this->aps = ['flint' => new AccessPoint('flint', '192.168.1.175', 'Bureau', 'eth1')];
    }

    /**
     * @param list<array{string, string, float}>   $fdb
     * @param list<array{string, string, int, int}> $stations
     */
    private static function snapshot(array $fdb = [], array $stations = []): OpenWrtSnapshot
    {
        return OpenWrtSnapshot::fromArray([
            'time' => self::NOW,
            'ports' => ['eth1' => [0, 0], 'lan2' => [0, 0], 'ra0' => [0, 0], 'rax0' => [0, 0]],
            'radios' => ['ra0' => 2.467, 'rax0' => 5.62],
            'fdb' => $fdb,
            'stations' => $stations,
        ], self::NOW);
    }

    /**
     * @param array<string, ?OpenWrtSnapshot> $snapshots
     * @param array<string, mixed>            $memory
     * @param list<string>                    $macs
     *
     * @return array{array<string, ?array<string, mixed>>, array<string, mixed>}
     */
    private function place(array $snapshots, array $memory = [], array $macs = [self::PC, self::PHONE, self::SERVER, self::TABLET, self::FLINT], int $now = self::NOW): array
    {
        return (new Placement('Baie', memory: 300, keep: 7 * 86400))->place($macs, $this->aps, $snapshots, [self::FLINT], $memory, $now);
    }

    public function testWifiStationTakesItsRadio(): void
    {
        [$places] = $this->place(['flint' => self::snapshot(
            fdb: [[self::PHONE, 'rax0', 0.5]],
            stations: [[self::PHONE, 'rax0', -38, 120]],
        )]);

        self::assertSame(['zone' => 'Bureau', 'ap' => 'flint', 'link' => 'wifi', 'port' => 'rax0', 'band' => '5', 'signal' => -38, 'seenAt' => null], $places[self::PHONE]);
    }

    public function testCableOnALanPortOfTheAccessPoint(): void
    {
        [$places] = $this->place(['flint' => self::snapshot(fdb: [[self::PC, 'lan2', 0.0]])]);

        self::assertSame(['zone' => 'Bureau', 'ap' => 'flint', 'link' => 'cable', 'port' => 'lan2', 'band' => null, 'signal' => null, 'seenAt' => null], $places[self::PC]);
    }

    public function testWifiWithoutStationEntryStillCountsOnItsRadio(): void
    {
        // Client d'un répéteur : le Flint ne connaît que sa MAC dans le bridge.
        [$places] = $this->place(['flint' => self::snapshot(fdb: [[self::TABLET, 'ra0', 3.0]])]);

        self::assertSame(['flint', 'wifi', 'ra0', '2.4', null], [$places[self::TABLET]['ap'], $places[self::TABLET]['link'], $places[self::TABLET]['port'], $places[self::TABLET]['band'], $places[self::TABLET]['signal']]);
    }

    public function testSeenOnlyUpstreamAndNeverOnAnAccessPointIsWiredOnTheSwitch(): void
    {
        [$places] = $this->place(['flint' => self::snapshot(fdb: [[self::SERVER, 'eth1', 1.0]])]);

        self::assertSame(['zone' => 'Baie', 'ap' => null, 'link' => 'cable', 'port' => null, 'band' => null, 'signal' => null, 'seenAt' => null], $places[self::SERVER]);
    }

    public function testAccessPointItselfIsOnTheSwitch(): void
    {
        [$places] = $this->place(['flint' => self::snapshot()]);

        self::assertSame('Baie', $places[self::FLINT]['zone']);
    }

    public function testUnseenDeviceIsUncertain(): void
    {
        [$places] = $this->place(['flint' => self::snapshot()]);

        self::assertNull($places[self::TABLET]);
    }

    public function testRecentlyLeftKeepsItsAccessPointForAWhile(): void
    {
        [, $memory] = $this->place(['flint' => self::snapshot(stations: [[self::PHONE, 'rax0', -38, 2000]])]);

        [$places] = $this->place(['flint' => self::snapshot(fdb: [[self::PHONE, 'eth1', 0.0]])], $memory, now: self::NOW + 240);

        self::assertSame(['zone' => 'Bureau', 'ap' => 'flint', 'link' => 'wifi', 'port' => 'rax0', 'band' => '5', 'signal' => -38, 'seenAt' => self::NOW - 2], $places[self::PHONE]);
    }

    public function testOnceSeenOnAnAccessPointItIsNotMistakenForTheSwitch(): void
    {
        // Parti vers un point d'accès que le dashboard ne lit pas : vu en amont seulement.
        [, $memory] = $this->place(['flint' => self::snapshot(stations: [[self::PHONE, 'rax0', -38, 0]])]);

        [$places] = $this->place(['flint' => self::snapshot(fdb: [[self::PHONE, 'eth1', 0.0]])], $memory, now: self::NOW + 600);

        self::assertNull($places[self::PHONE]);
    }

    public function testMemoryIsForgottenAfterKeep(): void
    {
        [, $memory] = $this->place(['flint' => self::snapshot(stations: [[self::PHONE, 'rax0', -38, 0]])]);

        [$places, $memory] = $this->place(['flint' => self::snapshot(fdb: [[self::PHONE, 'eth1', 0.0]])], $memory, now: self::NOW + 8 * 86400);

        self::assertSame('Baie', $places[self::PHONE]['zone']);
        self::assertArrayNotHasKey(self::PHONE, $memory);
    }

    public function testStaleBridgeEntryIsIgnored(): void
    {
        // Le bridge garde une MAC 5 min après son départ : au-delà d'une minute, elle ne place plus.
        [$places] = $this->place(['flint' => self::snapshot(fdb: [[self::PC, 'lan2', 200.0]])]);

        self::assertNull($places[self::PC]);
    }

    public function testUnreachableAccessPointFallsBackOnMemory(): void
    {
        [, $memory] = $this->place(['flint' => self::snapshot(fdb: [[self::PC, 'lan2', 0.0]])]);

        [$places] = $this->place(['flint' => null], $memory, now: self::NOW + 60);

        self::assertSame(['flint', 'lan2', self::NOW], [$places[self::PC]['ap'], $places[self::PC]['port'], $places[self::PC]['seenAt']]);
    }

    public function testFreshestAccessPointWinsWhenRoaming(): void
    {
        $this->aps['flint2'] = new AccessPoint('flint2', '192.168.1.176', 'Salon', 'eth1');

        [$places] = $this->place([
            'flint' => self::snapshot(stations: [[self::TABLET, 'rax0', -70, 9000]]),
            'flint2' => self::snapshot(stations: [[self::TABLET, 'ra0', -50, 100]]),
        ]);

        self::assertSame(['Salon', '2.4'], [$places[self::TABLET]['zone'], $places[self::TABLET]['band']]);
    }
}
