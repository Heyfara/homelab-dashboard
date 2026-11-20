<?php

namespace App\Tests\Card\Type;

use App\Card\Type\Gw2BossesCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use App\Profile\CardCredentials;
use App\Profile\ProfileCredentials;
use App\Profile\ProfileFiles;
use App\Source\Credentials;
use App\Source\Gw2\Gw2Client;
use App\Tests\Source\Gw2\Gw2ClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class Gw2BossesCardTest extends TestCase
{
    private Dashboard $dashboard;

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
    }

    protected function tearDown(): void
    {
        $dir = sys_get_temp_dir().'/gw2-bosses-card-'.getmypid();
        array_map('unlink', glob($dir.'/{,.}*.*', \GLOB_BRACE) ?: []);
        @rmdir($dir);
    }

    private function card(string $now = '2026-11-16T00:07:00+00:00', ?callable $respond = null): Gw2BossesCard
    {
        $respond ??= static fn (string $path) => Gw2ClientTest::homelab($path);
        $client = new Gw2Client(new MockHttpClient(function (string $m, string $url, array $options) use ($respond) {
            $this->requests[] = [$url, $options];

            return $respond((string) parse_url($url, \PHP_URL_PATH));
        }));
        $profiles = new ProfileCredentials(new ProfileFiles(sys_get_temp_dir().'/gw2-bosses-card-'.getmypid()), 'secret');
        $profiles->set('zoe', 'gw2', 'cle-du-profil', 'Zoe.1234');

        return new Gw2BossesCard($client, new CardCredentials(new Credentials(['GW2_API_KEY' => 'cle']), $profiles), $now);
    }

    private function instance(Gw2BossesCard $card, array $options = [], ?string $profile = 'zoe'): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('b', 'gw2-bosses', null, 2, 300, $resolver->resolve(['api' => 'https://gw2.test', ...$options]), 1, $profile);
    }

    private function fetch(array $options = [], string $now = '2026-11-16T00:07:00+00:00', ?callable $respond = null): array
    {
        $card = $this->card($now, $respond);

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptions(): void
    {
        self::assertEquals(['count' => 4, 'credentials' => null, 'api' => 'https://gw2.test'], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['count' => 0]);
    }

    public function testCredentialsNameIsChecked(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['credentials' => '../APP_SECRET']);
    }

    public function testDescribeLinksToTheWikiTimers(): void
    {
        $card = $this->card();

        self::assertSame(
            ['title' => 'Boss du monde', 'link' => 'https://wiki.guildwars2.com/wiki/Event_timers', 'count' => 3],
            $card->describe($this->instance($card, ['count' => 3]), $this->dashboard),
        );
    }

    public function testNextTwentyFourHoursWithBossesDoneToday(): void
    {
        $data = $this->fetch();

        self::assertCount(96, $data['slots']);
        self::assertSame(
            ['id' => 'admiral_taidha_covington', 'startsAt' => '2026-11-16T00:00:00+00:00', 'done' => true],
            $data['slots'][0],
            'en cours depuis 7 min, déjà fait',
        );
        self::assertSame(['svanir_shaman_chief', true], [$data['slots'][1]['id'], $data['slots'][1]['done']]);
        self::assertSame(['megadestroyer', false], [$data['slots'][2]['id'], $data['slots'][2]['done']]);
        self::assertSame(['admiral_taidha_covington', 'svanir_shaman_chief'], $data['done'], 'Tequatl et Drakkar ne comptent pas : hors des boss affichés');
        self::assertSame('2026-11-17T00:00:00+00:00', $data['resetAt']);
        self::assertNull($data['doneError']);
    }

    public function testWaypointIconFromTheApi(): void
    {
        self::assertSame('https://render.example/waypoint.png', $this->fetch()['waypointIcon']);
    }

    public function testBossSheetsOnce(): void
    {
        $bosses = $this->fetch()['bosses'];

        self::assertCount(10, $bosses);
        self::assertSame('Amiral Taidha Covington', $bosses['admiral_taidha_covington']['name']);
        self::assertSame('[&BKgBAAA=]', $bosses['admiral_taidha_covington']['waypoint']);
    }

    public function testPassagesAfterTheResetAreNotDone(): void
    {
        $data = $this->fetch(now: '2026-11-16T23:50:00+00:00');

        self::assertSame(['shadow_behemoth', 'admiral_taidha_covington'], [$data['slots'][0]['id'], $data['slots'][1]['id']]);
        self::assertSame('2026-11-17T00:00:00+00:00', $data['slots'][1]['startsAt']);
        self::assertFalse($data['slots'][1]['done'], 'fait aujourd\'hui, mais le passage de demain est à refaire');
    }

    public function testKeySavedInTheProfile(): void
    {
        $this->fetch(['credentials' => 'profile']);

        self::assertSame(['Authorization: Bearer cle-du-profil'], $this->requests[0][1]['normalized_headers']['authorization']);
    }

    public function testScheduleStillShownWithoutKey(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card, ['credentials' => 'profile'], profile: 'tom'), $this->dashboard);

        self::assertCount(96, $data['slots']);
        self::assertFalse($data['slots'][0]['done']);
        self::assertNull($data['done']);
        self::assertStringContainsString('Ma config', (string) $data['doneError']);
    }

    public function testScheduleStillShownWhenTheApiFails(): void
    {
        $data = $this->fetch(respond: static fn () => new MockResponse('', ['error' => 'Connexion refusée']));

        self::assertCount(96, $data['slots']);
        self::assertNull($data['done']);
        self::assertStringContainsString('injoignable', (string) $data['doneError']);
        self::assertNull($data['waypointIcon']);
    }
}
