<?php

namespace App\Tests\Card;

use App\Card\CardDataFetcher;
use App\Card\CardResult;
use App\Card\CardTypeRegistry;
use App\Card\DeferredRefresh;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use App\Profile\ProfileCredentials;
use App\Profile\ProfileSettings;
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

    private DeferredRefresh $deferred;

    protected function setUp(): void
    {
        $this->type = new FakeCard();
        $this->registry = new CardTypeRegistry(new ServiceLocator(['fake' => fn () => $this->type]));
        $this->deferred = new DeferredRefresh();
        $this->fetcher = new CardDataFetcher($this->registry, new ArrayAdapter(), new NullLogger(), $this->deferred);
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
    }

    private function card(array $options = ['label' => 'x', 'loud' => false], int $refresh = 30): CardInstance
    {
        return new CardInstance('c1', 'fake', null, 1, $refresh, $options);
    }

    /** refresh: 0 → données toujours périmées dès la lecture suivante. */
    private function expiredCard(): CardInstance
    {
        return $this->card(refresh: 0);
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
        $fetcher = new CardDataFetcher($this->registry, new ArrayAdapter(), new NullLogger(), new DeferredRefresh(), $keys);
        $card = new CardInstance('c1', 'fake', null, 1, 30, ['label' => 'x', 'loud' => false], 1, 'zoe');

        $fetcher->fetch($card, $this->dashboard);
        $fetcher->fetch($card, $this->dashboard);
        $keys->set('zoe', 'gw2', 'nouvelle-cle', null);
        $fetcher->fetch($card, $this->dashboard);

        self::assertSame(2, $this->type->fetchCount, 'clé ajoutée dans « Ma config » : données relues');
        array_map('unlink', glob($dir.'/{,.}*.*', \GLOB_BRACE) ?: []);
        @rmdir($dir);
    }

    public function testNewProfileSettingBypassesCache(): void
    {
        $dir = sys_get_temp_dir().'/fetcher-'.bin2hex(random_bytes(4));
        $settings = new ProfileSettings(new ProfileFiles($dir));
        $fetcher = new CardDataFetcher($this->registry, new ArrayAdapter(), new NullLogger(), new DeferredRefresh(), new ProfileCredentials(new ProfileFiles($dir), 'secret'), $settings);
        $card = new CardInstance('c1', 'fake', null, 1, 30, ['label' => 'x', 'loud' => false], 1, 'zoe');

        $fetcher->fetch($card, $this->dashboard);
        $settings->set('zoe', 'weather', ['place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49]);
        $fetcher->fetch($card, $this->dashboard);

        self::assertSame(2, $this->type->fetchCount, 'lieu choisi dans « Ma config » : données relues');
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

    public function testExpiredDataIsServedAtOnceAndRefreshedAfterResponse(): void
    {
        $this->fetcher->fetch($this->expiredCard(), $this->dashboard);

        $result = $this->fetcher->fetch($this->expiredCard(), $this->dashboard);
        self::assertSame(1, $result->data['count'], 'ancienne valeur servie sans attendre la source');
        self::assertTrue($result->stale);
        self::assertSame(1, $this->type->fetchCount, 'source pas encore relue pendant la requête');

        $this->deferred->run();
        self::assertSame(2, $this->type->fetchCount, 'relue une fois la réponse partie');
        self::assertSame(2, $this->fetcher->fetch($this->card(refresh: 3600), $this->dashboard)->data['count']);
    }

    public function testFreshDataIsNotStale(): void
    {
        $this->fetcher->fetch($this->card(), $this->dashboard);

        self::assertFalse($this->fetcher->fetch($this->card(), $this->dashboard)->stale);
        $this->deferred->run();
        self::assertSame(1, $this->type->fetchCount);
    }

    public function testOneBackgroundRefreshPerCardAndRequest(): void
    {
        $this->fetcher->fetch($this->expiredCard(), $this->dashboard);
        $this->fetcher->fetch($this->expiredCard(), $this->dashboard);
        $this->fetcher->fetch($this->expiredCard(), $this->dashboard);

        $this->deferred->run();
        self::assertSame(2, $this->type->fetchCount);
    }

    public function testBackgroundFailureIsReportedOnNextRead(): void
    {
        $this->fetcher->fetch($this->expiredCard(), $this->dashboard);
        $this->fetcher->fetch($this->expiredCard(), $this->dashboard);
        $this->type->failWith = new SourceUnavailableException('Beszel injoignable');
        $this->deferred->run();

        $result = $this->fetcher->fetch($this->expiredCard(), $this->dashboard);
        self::assertSame(CardResult::ERROR, $result->status);
        self::assertSame('Beszel injoignable', $result->error);

        $this->type->failWith = null;
        $this->deferred->run();
        self::assertSame(CardResult::OK, $this->fetcher->fetch($this->card(refresh: 3600), $this->dashboard)->status, 'source revenue : données à jour');
    }

    public function testJsonShape(): void
    {
        $json = json_decode(json_encode($this->fetcher->fetch($this->card(), $this->dashboard), \JSON_THROW_ON_ERROR), true);

        self::assertSame(['status', 'data', 'error', 'fetchedAt', 'stale'], array_keys($json));
        self::assertFalse($json['stale']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $json['fetchedAt']);
    }
}
