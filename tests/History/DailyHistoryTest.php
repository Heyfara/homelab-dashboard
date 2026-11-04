<?php

namespace App\Tests\History;

use App\History\DailyHistory;
use PHPUnit\Framework\TestCase;

final class DailyHistoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/history-'.bin2hex(random_bytes(4));
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

    private function history(string $now): DailyHistory
    {
        return new DailyHistory($this->dir, $now);
    }

    public function testEmptyWhenNothingRecorded(): void
    {
        self::assertSame([], $this->history('2026-11-04T10:00:00+01:00')->read('backblaze'));
    }

    public function testOneEntryPerDayMergedAndSorted(): void
    {
        $this->history('2026-11-05T08:00:00+01:00')->record('backblaze', ['sauvegardes' => 410]);
        $this->history('2026-11-04T08:00:00+01:00')->record('backblaze', ['sauvegardes' => 400, 'archives' => 2]);
        // Même jour : la dernière mesure remplace la précédente, les autres valeurs restent.
        $this->history('2026-11-04T22:00:00+01:00')->record('backblaze', ['sauvegardes' => 401]);

        self::assertSame([
            '2026-11-04' => ['sauvegardes' => 401, 'archives' => 2],
            '2026-11-05' => ['sauvegardes' => 410],
        ], $this->history('2026-11-05T09:00:00+01:00')->read('backblaze'));
    }

    public function testOldestDaysDropped(): void
    {
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $day) {
            $this->history($day.'T12:00:00+01:00')->record('backblaze', ['b' => 1], keep: 2);
        }

        self::assertSame(['2026-11-02', '2026-11-03'], array_keys($this->history('2026-11-03T12:00:00+01:00')->read('backblaze')));
    }

    public function testFilePrivate(): void
    {
        $this->history('2026-11-04T10:00:00+01:00')->record('backblaze', ['b' => 1]);

        self::assertSame('0600', substr(\sprintf('%o', fileperms($this->dir.'/backblaze.json')), -4));
        self::assertSame('0700', substr(\sprintf('%o', fileperms($this->dir)), -4));
    }

    public function testInvalidSeriesName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->history('2026-11-04T10:00:00+01:00')->record('../x', ['b' => 1]);
    }
}
