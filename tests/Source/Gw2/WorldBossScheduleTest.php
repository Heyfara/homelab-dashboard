<?php

namespace App\Tests\Source\Gw2;

use App\Source\Gw2\WorldBossSchedule;
use PHPUnit\Framework\TestCase;

final class WorldBossScheduleTest extends TestCase
{
    /**
     * @return list<array{string, string}> [heure UTC, identifiant]
     */
    private static function day(): array
    {
        return array_map(
            static fn (array $s): array => [$s['startsAt']->format('H:i'), $s['id']],
            WorldBossSchedule::upcoming(new \DateTimeImmutable('2026-11-16T00:00:00+00:00'), 96),
        );
    }

    public function testEveryQuarterHourHasExactlyOneBoss(): void
    {
        $day = self::day();

        self::assertCount(96, $day);
        self::assertSame('00:00', $day[0][0]);
        self::assertSame('23:45', $day[95][0]);
        self::assertSame(96, \count(array_unique(array_column($day, 0))));
    }

    public function testPassagesPerDayMatchTheWiki(): void
    {
        $counts = array_count_values(array_column(self::day(), 1));
        ksort($counts);

        self::assertSame([
            'admiral_taidha_covington' => 8,
            'claw_of_jormag' => 8,
            'fire_elemental' => 12,
            'great_jungle_wurm' => 12,
            'inquest_golem_mark_ii' => 8,
            'megadestroyer' => 8,
            'modniir_ulgoth' => 8,
            'shadow_behemoth' => 12,
            'svanir_shaman_chief' => 12,
            'the_shatterer' => 8,
        ], $counts);
    }

    /** Relevés dans le tableau « Full schedule » de https://wiki.guildwars2.com/wiki/World_boss */
    public function testSpotChecksFromTheWiki(): void
    {
        $day = array_column(self::day(), 1, 0);

        self::assertSame('admiral_taidha_covington', $day['00:00']);
        self::assertSame('svanir_shaman_chief', $day['00:15']);
        self::assertSame('the_shatterer', $day['01:00']);
        self::assertSame('great_jungle_wurm', $day['01:15']);
        self::assertSame('shadow_behemoth', $day['01:45']);
        self::assertSame('inquest_golem_mark_ii', $day['02:00']);
        self::assertSame('claw_of_jormag', $day['05:30']);
        self::assertSame('fire_elemental', $day['06:45']);
        self::assertSame('modniir_ulgoth', $day['04:30']);
        self::assertSame('megadestroyer', $day['06:30']);
    }

    public function testStartsWithTheBossInProgress(): void
    {
        $slots = WorldBossSchedule::upcoming(new \DateTimeImmutable('2026-11-16T20:07:00+00:00'), 3);

        self::assertSame(['20:00', '20:15', '20:30'], array_map(static fn (array $s): string => $s['startsAt']->format('H:i'), $slots));
        self::assertSame(['inquest_golem_mark_ii', 'svanir_shaman_chief', 'claw_of_jormag'], array_column($slots, 'id'));
        self::assertSame('Golem de l\'Enqueste Marque II', $slots[0]['name']);
    }

    public function testABossIsOverAfterFifteenMinutes(): void
    {
        $slots = WorldBossSchedule::upcoming(new \DateTimeImmutable('2026-11-16T20:15:00+00:00'), 1);

        self::assertSame('20:15', $slots[0]['startsAt']->format('H:i'));
    }

    public function testCrossesMidnight(): void
    {
        $slots = WorldBossSchedule::upcoming(new \DateTimeImmutable('2026-11-16T23:50:00+00:00'), 2);

        self::assertSame(['2026-11-16T23:45:00+00:00', '2026-11-17T00:00:00+00:00'], array_map(static fn (array $s): string => $s['startsAt']->format(\DATE_ATOM), $slots));
        self::assertSame(['shadow_behemoth', 'admiral_taidha_covington'], array_column($slots, 'id'));
    }

    public function testTimesAreUtcWhateverTheInputZone(): void
    {
        $slots = WorldBossSchedule::upcoming(new \DateTimeImmutable('2026-11-16T21:07:00+01:00'), 1);

        self::assertSame('2026-11-16T20:00:00+00:00', $slots[0]['startsAt']->format(\DATE_ATOM));
    }

    public function testTenBosses(): void
    {
        self::assertCount(10, WorldBossSchedule::ids());
    }
}
