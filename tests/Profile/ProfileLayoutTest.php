<?php

namespace App\Tests\Profile;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Profile;
use App\Dashboard\Model\Section;
use App\Profile\ProfileFiles;
use App\Profile\ProfileLayout;
use PHPUnit\Framework\TestCase;

final class ProfileLayoutTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/layout-'.bin2hex(random_bytes(4));
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

    private function layout(): ProfileLayout
    {
        return new ProfileLayout(new ProfileFiles($this->dir));
    }

    /**
     * @param list<list<string>> $sections identifiants des cartes, section par section
     */
    private static function profile(array $sections): Profile
    {
        return new Profile('alice', 'Alice', 'indigo', array_map(
            static fn (array $ids, int $i): Section => new Section('S'.$i, 3, array_map(static fn (string $id): CardInstance => new CardInstance($id, 'fake', null, 1, 20, []), $ids)),
            $sections,
            array_keys($sections),
        ));
    }

    /**
     * @return list<list<string>>
     */
    private static function ids(Profile $profile): array
    {
        return array_map(static fn (Section $s): array => array_map(static fn (CardInstance $c): string => $c->id, $s->cards), $profile->sections);
    }

    public function testNoSavedOrderKeepsTheYamlOrder(): void
    {
        $profile = self::profile([['a', 'b', 'c']]);

        self::assertSame([], $this->layout()->order('alice'));
        self::assertSame([['a', 'b', 'c']], self::ids($this->layout()->arrange($profile)));
    }

    public function testSavedOrderIsAppliedSectionBySection(): void
    {
        $this->layout()->save('alice', ['c', 'a', 'b', 'z', 'y']);

        self::assertSame(['c', 'a', 'b', 'z', 'y'], $this->layout()->order('alice'));
        self::assertSame([['c', 'a', 'b'], ['z', 'y']], self::ids($this->layout()->arrange(self::profile([['a', 'b', 'c'], ['y', 'z']]))));
    }

    public function testCardsAddedToTheYamlLaterGoAtTheEndOfTheirSection(): void
    {
        $this->layout()->save('alice', ['c', 'a', 'b', 'gone']);

        self::assertSame([['c', 'a', 'b', 'new1', 'new2']], self::ids($this->layout()->arrange(self::profile([['new1', 'a', 'b', 'new2', 'c']]))));
    }

    public function testCardsNeverChangeSection(): void
    {
        $this->layout()->save('alice', ['y', 'a', 'b']);

        self::assertSame([['a', 'b'], ['y']], self::ids($this->layout()->arrange(self::profile([['a', 'b'], ['y']]))));
    }

    public function testResetForgetsTheOrder(): void
    {
        $this->layout()->save('alice', ['b', 'a']);
        $this->layout()->reset('alice');

        self::assertSame([], $this->layout()->order('alice'));
        self::assertSame([['a', 'b']], self::ids($this->layout()->arrange(self::profile([['a', 'b']]))));
    }

    public function testPrivateFileNextToTheKeys(): void
    {
        $this->layout()->save('alice', ['a']);

        $file = $this->dir.'/alice.layout.json';
        self::assertFileExists($file);
        self::assertSame('0600', substr(\sprintf('%o', fileperms($file)), -4));
        self::assertSame('0700', substr(\sprintf('%o', fileperms($this->dir)), -4));
    }

    public function testProfileNameIsChecked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->layout()->save('../alice', ['a']);
    }
}
