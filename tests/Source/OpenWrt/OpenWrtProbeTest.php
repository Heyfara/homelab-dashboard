<?php

namespace App\Tests\Source\OpenWrt;

use App\Source\OpenWrt\OpenWrtProbe;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;

final class OpenWrtProbeTest extends TestCase
{
    private string $dir;

    /** @var list<list<string>> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/probe-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        touch($this->dir.'/id_ed25519');
        touch($this->dir.'/known_hosts');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    /**
     * @param array{int, string, string} $result
     */
    private function probe(array $result, ?string $dir = null): OpenWrtProbe
    {
        return new OpenWrtProbe($dir ?? $this->dir, function (array $argv) use ($result): array {
            $this->calls[] = $argv;

            return $result;
        }, static fn (): float => 1000.25);
    }

    public function testRunsTheProbeOverSshWithPinnedHostKey(): void
    {
        $snapshot = $this->probe([0, '{"time":1,"ports":{"lan2":[1,2]},"radios":{},"fdb":[],"stations":[]}', ''])->snapshot('192.168.1.175');

        self::assertSame(['lan2' => [1, 2]], $snapshot->ports);
        self::assertSame(1000.25, $snapshot->readAt);
        self::assertSame([
            'ssh', '-i', $this->dir.'/id_ed25519',
            '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile='.$this->dir.'/known_hosts',
            '-o', 'ConnectTimeout=3', '-o', 'LogLevel=ERROR',
            'root@192.168.1.175', 'dashboard-probe',
        ], $this->calls[0]);
    }

    public function testSshFailureIsExplained(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('192.168.1.175 injoignable en SSH : Host key verification failed.');

        $this->probe([255, '', "Host key verification failed.\n"])->snapshot('192.168.1.175');
    }

    public function testMissingKey(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('NETWORK_SSH_DIR');

        $this->probe([0, '', ''], '/nulle/part')->snapshot('192.168.1.175');
    }
}
