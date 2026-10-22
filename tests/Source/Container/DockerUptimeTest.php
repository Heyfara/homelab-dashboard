<?php

namespace App\Tests\Source\Container;

use App\Source\Container\DockerUptime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DockerUptimeTest extends TestCase
{
    /**
     * @return iterable<array{string, ?string}>
     */
    public static function statuses(): iterable
    {
        yield ['Up 7 days', '7 j'];
        yield ['Up 1 day', '1 j'];
        yield ['Up 3 hours', '3 h'];
        yield ['Up About an hour', '1 h'];
        yield ['Up 12 minutes', '12 min'];
        yield ['Up About a minute', '1 min'];
        yield ['Up 40 seconds', '40 s'];
        yield ['Up Less than a second', '< 1 s'];
        yield ['Up 2 weeks', '2 sem.'];
        yield ['Up 3 months', '3 mois'];
        yield ['Up 2 years', '2 ans'];
        yield ['Up 2 hours (Paused)', '2 h'];
        yield ['Up 5 minutes (healthy)', '5 min'];
        yield ['Restarting (1) 4 seconds ago', null];
        yield ['Exited (0) 2 hours ago', null];
    }

    #[DataProvider('statuses')]
    public function testShortFrench(string $status, ?string $expected): void
    {
        self::assertSame($expected, DockerUptime::short($status));
    }
}
