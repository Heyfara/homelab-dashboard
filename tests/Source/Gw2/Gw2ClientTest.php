<?php

namespace App\Tests\Source\Gw2;

use App\Source\Gw2\Gw2Client;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class Gw2ClientTest extends TestCase
{
    private const API = 'https://gw2.test';

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    private function client(?callable $respond = null): Gw2Client
    {
        $respond ??= static fn (string $path) => self::homelab($path);

        return new Gw2Client(new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$url, $options];

            return $respond((string) parse_url($url, \PHP_URL_PATH));
        }));
    }

    /** API fictive de Guild Wars 2, réutilisée par FakeHomelab. */
    public static function homelab(string $path): MockResponse
    {
        $file = match ($path) {
            '/v2/account' => 'account',
            '/v2/tokeninfo' => 'tokeninfo',
            '/v2/account/wallet' => 'wallet',
            '/v2/currencies' => 'currencies',
            '/v2/account/wizardsvault/daily' => 'wv-daily',
            '/v2/account/wizardsvault/weekly' => 'wv-weekly',
            '/v2/commerce/delivery' => 'delivery',
            '/v2/account/dailycrafting' => 'account-dailycrafting',
            '/v2/dailycrafting' => 'dailycrafting',
            '/v2/files' => 'files',
            default => throw new \LogicException('Requête non prévue : '.$path),
        };

        return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/gw2/'.$file.'.json'), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    public function testAccount(): void
    {
        $account = $this->client()->account(self::API, 'cle');

        self::assertSame(['Joueur.1234', 42, 321], [$account['name'], $account['fractal_level'], $account['wvw']['rank']]);
        self::assertContains('Authorization: Bearer cle', $this->requests[0][1]['headers']);
    }

    public function testTokenInfo(): void
    {
        self::assertSame(['account', 'wallet', 'progression', 'tradingpost'], $this->client()->tokenInfo(self::API, 'cle')['permissions']);
    }

    public function testPublicRoutesWithoutKey(): void
    {
        $this->client()->fileIcons(self::API, ['ui_coin_gold']);

        self::assertArrayNotHasKey('authorization', array_change_key_case($this->requests[0][1]['normalized_headers']));
    }

    public function testWalletWithFrenchCurrencyNamesInRequestedOrder(): void
    {
        $wallet = $this->client()->wallet(self::API, [1, 63, 4, 999], 'cle');

        self::assertSame([
            ['id' => 1, 'name' => 'Pièce', 'icon' => 'https://render.example/coin.png', 'value' => 1234567],
            ['id' => 63, 'name' => 'Acclamations astrales', 'icon' => 'https://render.example/astral.png', 'value' => 1500],
            ['id' => 4, 'name' => 'Gemme', 'icon' => 'https://render.example/gem.png', 'value' => 800],
        ], $wallet, 'monnaie absente du portefeuille : ignorée');
        parse_str((string) parse_url($this->requests[1][0], \PHP_URL_QUERY), $query);
        self::assertSame(['ids' => '1,63,4,999', 'lang' => 'fr'], $query);
    }

    public function testWizardsVaultInFrench(): void
    {
        $daily = $this->client()->wizardsVault(self::API, 'daily', 'cle');

        self::assertSame(2, $daily['meta_progress_current']);
        self::assertSame('Terminer une fractale', $daily['objectives'][2]['title']);
        parse_str((string) parse_url($this->requests[0][0], \PHP_URL_QUERY), $query);
        self::assertSame('fr', $query['lang']);
    }

    public function testDeliveryAndDailyCrafting(): void
    {
        $client = $this->client();

        self::assertSame(514400, $client->delivery(self::API, 'cle')['coins']);
        self::assertSame([1, 5], $client->dailyCrafting(self::API, 'cle'));
    }

    public function testFileIcons(): void
    {
        self::assertSame('https://render.example/gold.png', $this->client()->fileIcons(self::API, ['ui_coin_gold', 'map_waypoint'])['ui_coin_gold']);
        parse_str((string) parse_url($this->requests[0][0], \PHP_URL_QUERY), $query);
        self::assertSame('ui_coin_gold,map_waypoint', $query['ids'], 'seulement les icônes utiles');
    }

    public function testRefusedKeyNamesTheMissingPermission(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Clé Guild Wars 2 refusée (403) sur /v2/account/wallet : Requires scope wallet');

        $this->client(static fn () => new MockResponse('{"text":"Requires scope wallet"}', ['http_code' => 403]))->wallet(self::API, [1], 'cle');
    }

    public function testMissingKey(): void
    {
        $this->expectExceptionMessage('Clé Guild Wars 2 absente');

        $this->client()->account(self::API, '');
    }

    public function testUnreachable(): void
    {
        $this->expectExceptionMessage('API Guild Wars 2 injoignable');

        $this->client(static fn () => new MockResponse('', ['error' => 'Connexion refusée']))->account(self::API, 'cle');
    }
}
