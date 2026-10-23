<?php

namespace App\Tests\Profile;

use App\Profile\ProfileFiles;
use App\Profile\ProfileSettings;
use PHPUnit\Framework\TestCase;

final class ProfileSettingsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/settings-'.bin2hex(random_bytes(4));
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

    private function store(): ProfileSettings
    {
        return new ProfileSettings(new ProfileFiles($this->dir), '2026-10-24T10:00:00+00:00');
    }

    public function testSetGetAndRemove(): void
    {
        $store = $this->store();
        self::assertNull($store->get('zoe', 'weather'));

        $store->set('zoe', 'weather', ['place' => 'Lyon', 'latitude' => 45.76, 'longitude' => 4.84]);

        self::assertSame(['place' => 'Lyon', 'latitude' => 45.76, 'longitude' => 4.84, 'updatedAt' => '2026-10-24T10:00:00+00:00'], $store->get('zoe', 'weather'));
        self::assertNull($store->get('tom', 'weather'), 'chaque profil a ses réglages');

        $store->remove('zoe', 'weather');
        self::assertNull($store->get('zoe', 'weather'));
    }

    public function testRevisionChangesOnEachWrite(): void
    {
        $store = $this->store();
        $empty = $store->revision('zoe');

        $store->set('zoe', 'weather', ['place' => 'Lyon', 'latitude' => 45.76, 'longitude' => 4.84]);
        $lyon = $store->revision('zoe');
        $store->set('zoe', 'weather', ['place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49]);

        self::assertNotSame($empty, $lyon);
        self::assertNotSame($lyon, $store->revision('zoe'));
    }

    public function testPrivateFileApartFromTheKeys(): void
    {
        $this->store()->set('zoe', 'weather', ['place' => 'Lyon', 'latitude' => 45.76, 'longitude' => 4.84]);

        $file = $this->dir.'/zoe.settings.json';
        self::assertFileExists($file);
        self::assertSame('0600', substr(\sprintf('%o', fileperms($file)), -4));
    }
}
