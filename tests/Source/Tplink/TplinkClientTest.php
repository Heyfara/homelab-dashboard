<?php

namespace App\Tests\Source\Tplink;

use App\Source\SourceUnavailableException;
use App\Source\Tplink\TplinkClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TplinkClientTest extends TestCase
{
    private FakeArcher $archer;

    protected function setUp(): void
    {
        $this->archer = new FakeArcher();
    }

    private function client(string $password = 'motdepasse', ?ArrayAdapter $cache = null): TplinkClient
    {
        return new TplinkClient(new MockHttpClient($this->archer->router()), $cache ?? new ArrayAdapter(), $password, static fn (): float => 1000.5);
    }

    public function testAssociatedDevicesByBand(): void
    {
        $s = $this->client()->snapshot('192.168.1.133');

        self::assertSame(1000.5, $s->readAt);
        self::assertSame(['wlan2g' => 2.4, 'wlan5g' => 5.0], $s->radios);
        self::assertSame([
            ['mac' => 'D2:E0:66:53:9A:01', 'radio' => 'wlan2g', 'signal' => null, 'inactive' => 0],
            ['mac' => 'D6:82:50:88:F1:02', 'radio' => 'wlan5g', 'signal' => null, 'inactive' => 0],
            ['mac' => '00:16:3E:00:00:05', 'radio' => 'wlan2g', 'signal' => null, 'inactive' => 0],
        ], $s->stations);
        self::assertSame([], $s->fdb);
    }

    public function testLogsOutEveryTime(): void
    {
        $this->client()->snapshot('192.168.1.133');

        self::assertSame(['2', '16', '16', '7', '2', '11'], $this->archer->calls);
    }

    public function testReadOncePerMinute(): void
    {
        $cache = new ArrayAdapter();
        $this->client(cache: $cache)->snapshot('192.168.1.133');
        $this->client(cache: $cache)->snapshot('192.168.1.133');

        self::assertCount(6, $this->archer->calls);
    }

    public function testWrongPasswordIsExplainedAndRemembered(): void
    {
        $cache = new ArrayAdapter();
        $this->archer->wrongPassword = true;

        try {
            $this->client(cache: $cache)->snapshot('192.168.1.133');
            self::fail('exception attendue');
        } catch (SourceUnavailableException $e) {
            self::assertStringContainsString('Archer', $e->getMessage());
            self::assertStringContainsString('TPLINK_PASSWORD', $e->getMessage());
        }
        self::assertContains('11', $this->archer->calls);

        // Pas de nouvelle tentative avant une minute : une seule session d'administration à la fois sur l'Archer.
        $this->archer->calls = [];
        try {
            $this->client(cache: $cache)->snapshot('192.168.1.133');
            self::fail('exception attendue');
        } catch (SourceUnavailableException) {
        }
        self::assertSame([], $this->archer->calls);
    }

    public function testMissingPassword(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('TPLINK_PASSWORD');

        $this->client('')->snapshot('192.168.1.133');
    }

    public function testUnreachable(): void
    {
        $client = new TplinkClient(new MockHttpClient(new MockResponse('', ['error' => 'Connection refused'])), new ArrayAdapter(), 'motdepasse');

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Archer injoignable');

        $client->snapshot('192.168.1.133');
    }

    public function testPasswordEncodingMatchesTheRouterScript(): void
    {
        self::assertSame('r4lzUtxvyBefbwK', TplinkClient::encode('motdepasse'));
        self::assertSame('N8PPv89BtW8gdT4McwUAMWoo9MoCAYg', TplinkClient::encode('un-mot-de-passe-bien-plus-long!'));
    }
}
