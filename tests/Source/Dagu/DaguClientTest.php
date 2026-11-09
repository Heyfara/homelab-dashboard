<?php

namespace App\Tests\Source\Dagu;

use App\Source\Dagu\DaguClient;
use App\Source\Dagu\DaguRun;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DaguClientTest extends TestCase
{
    private const URL = 'https://taches.test';

    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    private function client(?MockHttpClient $http = null, string $key = 'cle-dagu'): DaguClient
    {
        return new DaguClient($http ?? new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = [$url, $options];

            return self::homelab($method, $url);
        }), $key);
    }

    /** Client HTTP vers une instance Dagu fictive (utilisé aussi par DaguCardTest). */
    public static function httpClient(): MockHttpClient
    {
        return new MockHttpClient(static fn (string $method, string $url): MockResponse => self::homelab($method, $url));
    }

    /**
     * Instance Dagu fictive : une sauvegarde nocturne en cours, un import
     * hebdomadaire réussi (dont un passage lancé à la main), une tâche jamais
     * lancée.
     */
    public static function homelab(string $method, string $url): MockResponse
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $run = static fn (string $label, string $start, string $end = '', string $trigger = 'scheduler'): array => [
            'name' => 'x', 'status' => 0, 'statusLabel' => $label, 'startedAt' => $start, 'finishedAt' => $end, 'triggerType' => $trigger,
        ];
        $dag = static fn (string $name, string $description, ?string $cron, string $next, array $latest): array => [
            'fileName' => $name,
            'dag' => ['name' => $name, 'description' => $description, 'labels' => ['dashboard'], 'schedule' => null === $cron ? [] : [['expression' => $cron, 'kind' => 'cron']]],
            'latestDAGRun' => $latest,
            'nextRun' => $next,
            'suspended' => false,
        ];

        return match ($path) {
            '/api/v1/dags' => new JsonMockResponse(['dags' => 'dashboard' === ($query['labels'] ?? null) ? [
                $dag('nuit', "Sauvegardes de la nuit — alpha, beta\nCode : https://git.test/nuit.sh", '5 3 * * *', '2026-11-10T03:05:00+01:00', $run('running', '2026-11-09T03:05:00.151+01:00')),
                $dag('papiers', 'Import des documents — alpha', '30 7 * * 1', '2026-11-16T07:30:00+01:00', $run('succeeded', '2026-11-09T07:30:00.239+01:00', '2026-11-09T07:30:17.267+01:00')),
                $dag('neuve', '', null, '', $run('not_started', '')),
            ] : [], 'errors' => [], 'pagination' => ['totalRecords' => 3]]),
            '/api/v1/dags/nuit/dag-runs' => new JsonMockResponse(['dagRuns' => [
                $run('running', '2026-11-09T03:05:00.151+01:00'),
                $run('partially_succeeded', '2026-11-08T03:05:00.100+01:00', '2026-11-08T03:15:59.000+01:00'),
                $run('failed', '2026-11-07T03:05:00.100+01:00', '2026-11-07T03:06:00.000+01:00'),
            ]]),
            '/api/v1/dags/papiers/dag-runs' => new JsonMockResponse(['dagRuns' => [
                $run('succeeded', '2026-11-09T07:30:00.239+01:00', '2026-11-09T07:30:17.267+01:00'),
                $run('succeeded', '2026-11-05T15:00:00.000+01:00', '2026-11-05T15:00:20.000+01:00', 'manual'),
            ]]),
            '/api/v1/dags/neuve/dag-runs' => new JsonMockResponse(['dagRuns' => []]),
            default => throw new \LogicException(\sprintf('Requête Dagu non prévue : %s %s', $method, $url)),
        };
    }

    public function testTasksWithTheirRuns(): void
    {
        $tasks = $this->client()->tasks(self::URL.'/', 'dashboard', 10);

        self::assertSame(['nuit', 'papiers', 'neuve'], array_map(static fn ($t): string => $t->name, $tasks));

        $nuit = $tasks[0];
        self::assertSame('Sauvegardes de la nuit', $nuit->description);
        self::assertSame(['5 3 * * *'], $nuit->schedules);
        self::assertEquals(new \DateTimeImmutable('2026-11-10T03:05:00+01:00'), $nuit->nextRun);
        self::assertFalse($nuit->suspended);
        self::assertSame(['running', 'partial', 'failed'], array_map(static fn (DaguRun $r): string => $r->state, $nuit->runs));
        self::assertNull($nuit->runs[0]->finishedAt);
        self::assertEquals(new \DateTimeImmutable('2026-11-08T03:15:59+01:00'), $nuit->runs[1]->finishedAt);

        self::assertSame([false, true], array_map(static fn (DaguRun $r): bool => $r->manual, $tasks[1]->runs));

        self::assertSame([], $tasks[2]->runs);
        self::assertSame([], $tasks[2]->schedules);
        self::assertNull($tasks[2]->nextRun);
        self::assertNull($tasks[2]->description);
    }

    public function testRequestsUseTheKeyAndTheRunLimit(): void
    {
        $this->client()->tasks(self::URL, 'dashboard', 7);

        self::assertSame('https://taches.test/api/v1/dags?labels=dashboard&perPage=100', $this->requests[0][0]);
        self::assertContains('Authorization: Bearer cle-dagu', $this->requests[0][1]['headers']);
        self::assertSame('https://taches.test/api/v1/dags/nuit/dag-runs?limit=7', $this->requests[1][0]);
    }

    public function testNoTaskWithTheLabel(): void
    {
        self::assertSame([], $this->client()->tasks(self::URL, 'autre', 10));
    }

    public function testMissingKey(): void
    {
        $this->expectExceptionObject(new SourceUnavailableException('Clé API Dagu absente (DAGU_API_KEY)'));
        $this->client(key: '')->tasks(self::URL, 'dashboard', 10);
    }

    public function testRefusedKey(): void
    {
        $this->expectExceptionObject(new SourceUnavailableException('Clé API Dagu refusée (401), vérifier DAGU_API_KEY'));
        $this->client(new MockHttpClient(new JsonMockResponse(['message' => 'unauthorized'], ['http_code' => 401])))->tasks(self::URL, 'dashboard', 10);
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/^Dagu injoignable : /');
        $this->client(new MockHttpClient(new MockResponse('', ['error' => 'Connexion refusée'])))->tasks(self::URL, 'dashboard', 10);
    }
}
