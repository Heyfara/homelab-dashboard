<?php

namespace App\Tests\Profile;

use App\Profile\ProfileCredentials;
use App\Profile\ProfileFiles;
use PHPUnit\Framework\TestCase;

final class ProfileCredentialsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/profiles-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', \GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    private function store(string $secret = 'secret-de-test'): ProfileCredentials
    {
        return new ProfileCredentials(new ProfileFiles($this->dir), $secret, '2026-10-22T10:00:00+00:00');
    }

    public function testSetGetAndList(): void
    {
        $store = $this->store();
        $store->set('zoe', 'gw2', 'CLE-TRES-SECRETE', 'Zoe.1234');

        self::assertSame('CLE-TRES-SECRETE', $store->get('zoe', 'gw2'));
        self::assertNull($store->get('zoe', 'immich'));
        self::assertNull($store->get('tom', 'gw2'), 'chaque profil a ses clés');
        self::assertSame(['gw2' => ['description' => 'Zoe.1234', 'updatedAt' => '2026-10-22T10:00:00+00:00']], $store->list('zoe'));
    }

    public function testNeverStoredInClearAndPrivateFile(): void
    {
        $this->store()->set('zoe', 'gw2', 'CLE-TRES-SECRETE', 'Zoe.1234');

        $file = $this->dir.'/zoe.credentials.json';
        self::assertStringNotContainsString('CLE-TRES-SECRETE', (string) file_get_contents($file));
        self::assertSame('0600', substr(\sprintf('%o', fileperms($file)), -4));
        self::assertSame('0700', substr(\sprintf('%o', fileperms($this->dir)), -4));
    }

    public function testAnotherSecretCannotRead(): void
    {
        $this->store()->set('zoe', 'gw2', 'CLE-TRES-SECRETE', null);

        self::assertNull($this->store('autre-secret')->get('zoe', 'gw2'), 'APP_SECRET changé : clé illisible, pas d\'erreur');
    }

    public function testValueCopiedToAnotherProfileIsUnreadable(): void
    {
        $this->store()->set('zoe', 'gw2', 'CLE-TRES-SECRETE', null);
        $data = json_decode((string) file_get_contents($this->dir.'/zoe.credentials.json'), true);
        file_put_contents($this->dir.'/tom.credentials.json', json_encode($data));

        self::assertNull($this->store()->get('tom', 'gw2'), 'chiffrement lié au profil et au service');
    }

    public function testRemove(): void
    {
        $store = $this->store();
        $store->set('zoe', 'gw2', 'A', null);
        $store->set('zoe', 'jelu', 'B', null);
        $store->remove('zoe', 'gw2');

        self::assertNull($store->get('zoe', 'gw2'));
        self::assertSame('B', $store->get('zoe', 'jelu'));
    }

    public function testReplace(): void
    {
        $store = $this->store();
        $store->set('zoe', 'gw2', 'ANCIENNE', 'Ancien.1');
        $store->set('zoe', 'gw2', 'NOUVELLE', 'Nouveau.2');

        self::assertSame('NOUVELLE', $store->get('zoe', 'gw2'));
        self::assertSame('Nouveau.2', $store->list('zoe')['gw2']['description']);
    }

    public function testRevisionChangesWithTheKeys(): void
    {
        $store = $this->store();
        $empty = $store->revision('zoe');
        $store->set('zoe', 'gw2', 'A', null);
        $first = $store->revision('zoe');
        $store->set('zoe', 'gw2', 'B', null);

        self::assertNotSame($empty, $first);
        self::assertNotSame($first, $store->revision('zoe'), 'même dans la même seconde');
    }

    public function testNoFileForAProfileWithoutKeys(): void
    {
        self::assertSame([], $this->store()->list('lea'));
        self::assertFileDoesNotExist($this->dir.'/lea.credentials.json');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'profil qui remonte' => ['../etc', 'gw2'];
        yield 'profil avec barre' => ['a/b', 'gw2'];
        yield 'service bizarre' => ['zoe', 'gw2.json'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNames')]
    public function testNamesAreChecked(string $profile, string $kind): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store()->set($profile, $kind, 'x', null);
    }
}
