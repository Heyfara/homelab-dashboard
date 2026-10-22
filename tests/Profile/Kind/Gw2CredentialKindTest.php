<?php

namespace App\Tests\Profile\Kind;

use App\Profile\InvalidCredentialException;
use App\Profile\Kind\Gw2CredentialKind;
use App\Source\Gw2\Gw2Client;
use App\Tests\Source\Gw2\Gw2ClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class Gw2CredentialKindTest extends TestCase
{
    private function kind(?callable $respond = null): Gw2CredentialKind
    {
        $respond ??= static fn (string $path) => Gw2ClientTest::homelab($path);

        return new Gw2CredentialKind(new Gw2Client(new MockHttpClient(static fn (string $m, string $url) => $respond((string) parse_url($url, \PHP_URL_PATH)))), 'https://gw2.test');
    }

    public function testDescribesItself(): void
    {
        $kind = $this->kind();

        self::assertSame(['gw2', 'Guild Wars 2'], [$kind->id(), $kind->label()]);
        self::assertStringContainsString('account.arena.net', $kind->help());
    }

    public function testValidKeyReturnsTheAccountName(): void
    {
        self::assertSame('Joueur.1234', $this->kind()->verify('cle'));
    }

    public function testMissingPermissionsAreNamed(): void
    {
        $kind = $this->kind(static fn (string $path) => '/v2/tokeninfo' === $path
            ? new JsonMockResponse(['permissions' => ['account', 'characters']])
            : Gw2ClientTest::homelab($path));

        $this->expectException(InvalidCredentialException::class);
        $this->expectExceptionMessage('Il manque des droits à cette clé : wallet, progression, tradingpost.');
        $kind->verify('cle');
    }

    public function testRejectedKey(): void
    {
        $this->expectException(InvalidCredentialException::class);
        $this->expectExceptionMessage('Clé refusée par Guild Wars 2');

        $this->kind(static fn () => new MockResponse('{"text":"Invalid access token"}', ['http_code' => 401]))->verify('mauvaise');
    }

    public function testEmptyKey(): void
    {
        $this->expectException(InvalidCredentialException::class);

        $this->kind()->verify('   ');
    }
}
