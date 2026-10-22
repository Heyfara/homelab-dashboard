<?php

namespace App\Tests\Source\Container;

use App\Dashboard\Model\Host;
use App\Source\Beszel\BeszelClient;
use App\Source\Container\BeszelContainerSource;
use App\Source\Container\ContainerState;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class BeszelContainerSourceTest extends TestCase
{
    private const NOW = 1792400000;

    /**
     * @param list<array<string, mixed>> $containers
     */
    private function source(array $containers, string $systemStatus = 'up'): BeszelContainerSource
    {
        $http = new MockHttpClient(static fn (string $method, string $url) => match (true) {
            str_contains($url, 'auth-with-password') => new JsonMockResponse(['token' => 't']),
            str_contains($url, '/systems/') => new JsonMockResponse(['items' => [['id' => 's1', 'name' => 'Beta', 'status' => $systemStatus]]]),
            str_contains($url, '/containers/') => new JsonMockResponse(['items' => $containers]),
        });

        return new BeszelContainerSource(new BeszelClient($http, new ArrayAdapter(), 'http://beszel', 'u', 'p'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(string $name, string $status, int $health = 0, int $ageSeconds = 0): array
    {
        return ['id' => 'x', 'name' => $name, 'status' => $status, 'health' => $health, 'cpu' => 1.5, 'memory' => 200, 'updated' => (self::NOW - $ageSeconds) * 1000];
    }

    /**
     * @param list<array<string, mixed>> $containers
     */
    private function state(array $containers, string $name = 'mealie'): ContainerState
    {
        return $this->source($containers)->status(new Host('beta', 'Beta'), $name);
    }

    public function testRunningAndHealthy(): void
    {
        self::assertEquals(
            new ContainerState('running', 'healthy', 'Up 7 days', 1.5, 200.0),
            $this->state([self::record('mealie', 'Up 7 days', 2)]),
        );
    }

    public function testHealthCodes(): void
    {
        self::assertNull($this->state([self::record('mealie', 'Up 1 hour', 0)])->health);
        self::assertSame('starting', $this->state([self::record('mealie', 'Up 5 seconds', 1)])->health);
        self::assertSame('unhealthy', $this->state([self::record('mealie', 'Up 1 hour', 3)])->health);
    }

    public function testRestartingAndPaused(): void
    {
        self::assertSame('restarting', $this->state([self::record('mealie', 'Restarting (1) 4 seconds ago')])->status);
        self::assertSame('paused', $this->state([self::record('mealie', 'Up 2 hours (Paused)')])->status);
    }

    public function testAbsentContainerIsStopped(): void
    {
        // L'agent ne remonte que les containers qui tournent.
        self::assertEquals(ContainerState::stopped(), $this->state([self::record('autre', 'Up 1 day')]));
    }

    public function testRecordLeftBehindIsStopped(): void
    {
        // Le hub garde ~10 min la fiche d'un container arrêté : elle n'est plus
        // mise à jour, contrairement à celles des containers qui tournent.
        self::assertSame('stopped', $this->state([
            self::record('mealie', 'Up 7 days', 0, 300),
            self::record('autre', 'Up 1 day'),
        ])->status);
    }

    public function testAllContainersOfAHost(): void
    {
        $all = $this->source([
            self::record('mealie', 'Up 7 days', 2),
            self::record('ancien', 'Up 9 days', 0, 300),
        ])->all(new Host('beta', 'Beta'));

        self::assertSame(['mealie', 'ancien'], array_keys($all));
        self::assertSame('healthy', $all['mealie']->health);
        self::assertSame('stopped', $all['ancien']->status, 'fiche plus mise à jour');
    }

    public function testSystemNotReportingIsAnError(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Beszel ne reçoit plus les données de Beta (état : down)');

        $this->source([], 'down')->status(new Host('beta', 'Beta'), 'mealie');
    }

    public function testHostWithoutBeszelSystem(): void
    {
        $this->expectException(\LogicException::class);

        $this->source([])->status(new Host('gamma'), 'pihole');
    }
}
