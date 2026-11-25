<?php

namespace App\Tests\Network;

use App\Network\ApSnapshot;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;

final class ApSnapshotTest extends TestCase
{
    public function testFromProbeOutput(): void
    {
        $json = '{"time":1795564254,"ports":{"eth1":[10,20],"lan2":[30,40],"rax0":[50,60]},"radios":{"rax0":5.62},'
            .'"fdb":[["00:16:3E:00:00:01","lan2",0.00],["00:16:3E:00:00:02","eth1",6.53]],'
            .'"stations":[["00:16:3E:00:00:03","rax0",-27,0]]}';

        $s = ApSnapshot::fromJson($json, 1000.5);

        self::assertSame(1000.5, $s->readAt);
        self::assertSame(['eth1' => [10, 20], 'lan2' => [30, 40], 'rax0' => [50, 60]], $s->ports);
        self::assertSame(['rax0' => 5.62], $s->radios);
        self::assertSame([['mac' => '00:16:3E:00:00:01', 'port' => 'lan2', 'age' => 0.0], ['mac' => '00:16:3E:00:00:02', 'port' => 'eth1', 'age' => 6.53]], $s->fdb);
        self::assertSame([['mac' => '00:16:3E:00:00:03', 'radio' => 'rax0', 'signal' => -27, 'inactive' => 0]], $s->stations);
    }

    public function testRadioWithoutClients(): void
    {
        $s = ApSnapshot::fromJson('{"time":1,"ports":{},"radios":{},"fdb":[],"stations":[]}', 1.0);

        self::assertSame([], $s->stations);
    }

    public function testUnreadableOutput(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Relevé illisible');

        ApSnapshot::fromJson('sh: dashboard-probe: not found', 1.0);
    }
}
