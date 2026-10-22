<?php

namespace App\Tests\Card\Type;

use App\Card\Type\Gw2Card;
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
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class Gw2CardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
    }

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    private function client(): Gw2Client
    {
        return new Gw2Client(new MockHttpClient(function (string $m, string $url, array $options) {
            $this->requests[] = [$url, $options];

            return Gw2ClientTest::homelab((string) parse_url($url, \PHP_URL_PATH));
        }));
    }

    protected function tearDown(): void
    {
        $dir = sys_get_temp_dir().'/gw2-card-'.getmypid();
        array_map('unlink', glob($dir.'/{,.}*.*', \GLOB_BRACE) ?: []);
        @rmdir($dir);
    }

    private function credentials(): CardCredentials
    {
        $profiles = new ProfileCredentials(new ProfileFiles(sys_get_temp_dir().'/gw2-card-'.getmypid()), 'secret');
        $profiles->set('zoe', 'gw2', 'cle-du-profil', 'Zoe.1234');

        return new CardCredentials(new Credentials(['GW2_API_KEY' => 'cle', 'GW2_API_KEY_ZOE' => 'cle-zoe']), $profiles);
    }

    /** « Maintenant » : mercredi 21 octobre 2026, 10 h UTC. */
    private function card(): Gw2Card
    {
        return new Gw2Card($this->client(), $this->credentials(), '2026-10-21T10:00:00+00:00');
    }

    private function instance(Gw2Card $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('g', 'gw2', null, 2, 300, $resolver->resolve(['api' => 'https://gw2.test', ...$options]), 2, 'zoe');
    }

    private function fetch(array $options = []): array
    {
        $card = $this->card();

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptions(): void
    {
        self::assertEquals(['currencies' => [1, 63, 2, 4], 'credentials' => null, 'api' => 'https://gw2.test'], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['currencies' => ['or']]);
    }

    public function testCredentialsNameIsChecked(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['credentials' => '../APP_SECRET']);
    }

    public function testAnotherPersonsAccount(): void
    {
        $this->fetch(['credentials' => 'zoe']);

        $authorized = array_filter($this->requests, static fn (array $r): bool => isset($r[1]['normalized_headers']['authorization']));
        self::assertNotEmpty($authorized);
        foreach ($authorized as [, $options]) {
            self::assertSame(['Authorization: Bearer cle-zoe'], $options['normalized_headers']['authorization']);
        }
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Guild Wars 2'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testAccountWalletAndDelivery(): void
    {
        $data = $this->fetch();

        self::assertSame(['name' => 'Joueur.1234', 'fractalLevel' => 42, 'wvwRank' => 321], $data['account']);
        self::assertSame([1, 63, 2, 4], array_column($data['wallet'], 'id'));
        self::assertSame(['coins' => 514400, 'items' => 1], $data['delivery']);
        self::assertSame(['gold' => 'https://render.example/gold.png', 'silver' => 'https://render.example/silver.png', 'copper' => 'https://render.example/copper.png'], $data['coinIcons']);
        self::assertSame('https://render.example/waypoint.png', $data['icon']);
        self::assertSame(['done' => 1, 'total' => 5], $data['crafting']);
    }

    public function testWizardsVaultProgressAndResets(): void
    {
        $vault = $this->fetch()['vault'];

        self::assertSame(['done' => 2, 'total' => 4, 'claimed' => false, 'acclaim' => 20, 'resetAt' => '2026-10-22T00:00:00+00:00'], $vault['daily']);
        self::assertSame(['done' => 1, 'total' => 6, 'claimed' => false, 'acclaim' => 450, 'resetAt' => '2026-10-26T07:30:00+00:00'], $vault['weekly']);
    }

    public function testOnlyUnfinishedObjectivesDailyFirst(): void
    {
        self::assertSame([
            ['period' => 'daily', 'title' => 'Terminer une fractale', 'current' => 0, 'complete' => 1, 'acclaim' => 10],
            ['period' => 'daily', 'title' => 'Récolter 10 ressources', 'current' => 4, 'complete' => 10, 'acclaim' => 10],
            ['period' => 'weekly', 'title' => 'Vaincre un boss du monde', 'current' => 0, 'complete' => 1, 'acclaim' => 50],
        ], $this->fetch()['vault']['objectives']);
    }

    public function testKeySavedInTheProfile(): void
    {
        $this->fetch(['credentials' => 'profile']);

        $authorized = array_filter($this->requests, static fn (array $r): bool => isset($r[1]['normalized_headers']['authorization']));
        foreach ($authorized as [, $options]) {
            self::assertSame(['Authorization: Bearer cle-du-profil'], $options['normalized_headers']['authorization']);
        }
    }

    public function testWeeklyResetLaterTheSameMonday(): void
    {
        $card = new Gw2Card($this->client(), $this->credentials(), '2026-10-26T06:00:00+00:00');

        self::assertSame('2026-10-26T07:30:00+00:00', $card->fetch($this->instance($card), $this->dashboard)['vault']['weekly']['resetAt']);
    }
}
