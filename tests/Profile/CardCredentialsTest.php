<?php

namespace App\Tests\Profile;

use App\Dashboard\Model\CardInstance;
use App\Profile\CardCredentials;
use App\Profile\ProfileCredentials;
use App\Profile\ProfileFiles;
use App\Source\Credentials;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;

final class CardCredentialsTest extends TestCase
{
    private string $dir;
    private ProfileCredentials $profiles;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/card-credentials-'.bin2hex(random_bytes(4));
        $this->profiles = new ProfileCredentials(new ProfileFiles($this->dir), 'secret');
        $this->profiles->set('zoe', 'gw2', 'cle-saisie-par-zoe', 'Zoe.1234');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/{,.}*.*', \GLOB_BRACE) ?: []);
        @rmdir($this->dir);
    }

    private function resolver(): CardCredentials
    {
        return new CardCredentials(new Credentials(['GW2_API_KEY' => 'cle-env', 'GW2_API_KEY_LEA' => 'cle-env-lea']), $this->profiles);
    }

    private function card(?string $credentials, string $profile = 'zoe'): CardInstance
    {
        return new CardInstance('c', 'gw2', null, 2, 300, ['credentials' => $credentials], 2, $profile);
    }

    public function testWithoutOptionTheEnvironmentKey(): void
    {
        self::assertSame('cle-env', $this->resolver()->resolve($this->card(null), 'GW2_API_KEY', 'gw2'));
    }

    public function testProfileTheKeySavedInMyKeys(): void
    {
        self::assertSame('cle-saisie-par-zoe', $this->resolver()->resolve($this->card('profile'), 'GW2_API_KEY', 'gw2'));
    }

    public function testProfileWithoutSavedKeySaysWhereToAddIt(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Pas encore de clé pour ce profil : l\'ajouter dans « Mes clés ».');

        $this->resolver()->resolve($this->card('profile', 'tom'), 'GW2_API_KEY', 'gw2');
    }

    public function testNamedVariantFromTheEnvironment(): void
    {
        self::assertSame('cle-env-lea', $this->resolver()->resolve($this->card('lea'), 'GW2_API_KEY', 'gw2'));
    }
}
