<?php

namespace App\Tests\Card;

use App\Card\CardDataFetcher;
use App\Card\CardResult;
use App\Card\CardTypeRegistry;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use App\Profile\ProfileCredentials;
use App\Profile\ProfileFiles;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class CardDataFetcherTest extends TestCase
{
    private FakeCard $type;
    private CardDataFetcher $fetcher;
    private Dashboard $dashboard;

    private CardTypeRegistry $registry;

    protected function setUp(): void
    {
        $this->type = new FakeCard();
        $this->registry = new CardTypeRegistry(new ServiceLocator(['fake' => fn () => $this->type]));
        $this->fetcher = new CardDataFetcher($this->registry, new ArrayAdapter(), new NullLogger());
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
    }

    private function card(array $options = ['label' => 'x', 'loud' => false]): CardInstance
    {
        return new CardInstance('c1', 'fake', null, 1, 30, $options);
    }

    public function testReturnsData(): void
    {
        $result = $this->fetcher->fetch($this->card(), $this->dashboard);

        self::assertSame(CardResult::OK, $result->status);
        self::assertSame(['count' => 1, 'loud' => false], $result->data);
        self::assertNull($result->error);
    }

    public function testCachesData(): void
    {
        $this->fetcher->fetch($this->card(), $this->dashboard);
        $result = $this->fetcher->fetch($this->card(), $this->dashboard);

        self::assertSame(1, $this->type->fetchCount);
        self::assertSame(1, $result->data['count']);
    }

    public function testOptionsChangeBypassesCache(): void
    {
        $this->fetcher->fetch($this->card(), $this->dashboard);
        $result = $this->fetcher->fetch($this->card(['label' => 'x', 'loud' => true]), $this->dashboard);

        self::assertSame(2, $this->type->fetchCount);
        self::assertTrue($result->data['loud']);
    }

    public function testNewProfileKeyBypassesCache(): void
    {
        $dir = sys_get_temp_dir().'/fetcher-'.bin2hex(random_bytes(4));
        $keys = new ProfileCredentials(new ProfileFiles($dir), 'secret');
        $fetcher = new CardDataFetcher($this->registry, new ArrayAdapter(), new NullLogger(), $keys);
        $card = new CardInstance('c1', 'fake', null, 1, 30, ['label' => 'x', 'loud' => false], 1, 'zoe');

        $fetcher->fetch($card, $this->dashboard);
        $fetcher->fetch($card, $this->dashboard);
        $keys->set('zoe', 'gw2', 'nouvelle-cle', null);
        $fetcher->fetch($card, $this->dashboard);

        self::assertSame(2, $this->type->fetchCount, 'clé ajoutée dans « Mes clés » : données relues');
        array_map('unlink', glob($dir.'/{,.}*.*', \GLOB_BRACE) ?: []);
        @rmdir($dir);
    }

    public function testSourceErrorBecomesErrorResultAndIsNotCached(): void
    {
        $this->type->failWith = new SourceUnavailableException('Beszel injoignable');

        $result = $this->fetcher->fetch($this->card(), $this->dashboard);
        self::assertSame(CardResult::ERROR, $result->status);
        self::assertSame('Beszel injoignable', $result->error);
        self::assertNull($result->data);

        $this->type->failWith = null;
        self::assertSame(CardResult::OK, $this->fetcher->fetch($this->card(), $this->dashboard)->status);
    }

    public function testUnexpectedErrorIsReportedToo(): void
    {
        $this->type->failWith = new \TypeError('boum');

        $result = $this->fetcher->fetch($this->card(), $this->dashboard);

        self::assertSame(CardResult::ERROR, $result->status);
        self::assertStringContainsString('boum', (string) $result->error);
    }

    public function testFeedHasItsOwnCacheAndData(): void
    {
        $this->fetcher->fetch($this->card(), $this->dashboard);
        $live = $this->fetcher->fetch($this->card(), $this->dashboard, 'live');
        $this->fetcher->fetch($this->card(), $this->dashboard, 'live');

        self::assertSame(['feed' => 'live', 'count' => 1], $live->data);
        self::assertSame(1, $this->type->fetchCount, 'le flux ne relance pas les données principales');
        self::assertSame(1, $this->type->feedCount, 'le flux a son propre cache');
    }

    public function testUnknownFeed(): void
    {
        $result = $this->fetcher->fetch($this->card(), $this->dashboard, 'inexistant');

        self::assertSame(CardResult::ERROR, $result->status);
        self::assertStringContainsString('inexistant', (string) $result->error);
    }

    public function testJsonShape(): void
    {
        $json = json_decode(json_encode($this->fetcher->fetch($this->card(), $this->dashboard), \JSON_THROW_ON_ERROR), true);

        self::assertSame(['status', 'data', 'error', 'fetchedAt'], array_keys($json));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $json['fetchedAt']);
    }
}
