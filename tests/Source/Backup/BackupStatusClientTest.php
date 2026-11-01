<?php

namespace App\Tests\Source\Backup;

use App\Source\Backup\BackupEntry;
use App\Source\Backup\BackupRun;
use App\Source\Backup\ResticRun;
use App\Source\Backup\BackupStatusClient;
use App\Source\Healthchecks\HealthchecksClient;
use App\Source\SourceUnavailableException;
use App\Tests\Source\Healthchecks\HealthchecksClientTest;
use PHPUnit\Framework\TestCase;

final class BackupStatusClientTest extends TestCase
{
    private const URL = 'https://healthchecks.test';

    /**
     * @param string|null $report rapport de la sentinelle, sinon celui de tests/fixtures/backup-status.json
     */
    private function client(?string $report = null): BackupStatusClient
    {
        return new BackupStatusClient(new HealthchecksClient(HealthchecksClientTest::httpClient(null === $report ? null : ['sentinel' => [$report]]), 'cle-hc'));
    }

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../fixtures/backup-status.json'), true);
    }

    public function testStatus(): void
    {
        $status = $this->client()->status(self::URL, 'sentinel');

        self::assertEquals(new \DateTimeImmutable('2026-10-20T08:00:01+02:00'), $status->checkedAt);
        self::assertCount(4, $status->entries);
        self::assertEquals(new BackupEntry('cuisine', 'ok', 5.0, 30.0, 'cuisine-20261020-030001.tar.gz', null), $status->entries[0]);
        self::assertEquals(new BackupEntry('notes', 'unreachable', null, 30.0, null, 'dossier inaccessible (beta down ou NFS bloqué)'), $status->entries[3]);
    }

    public function testHistoryOldestFirst(): void
    {
        $history = $this->client()->status(self::URL, 'sentinel')->history;

        self::assertCount(3, $history);
        self::assertEquals(new BackupRun(new \DateTimeImmutable('2026-10-19T08:00:01+02:00'), ['cuisine' => 'ok', 'galerie' => 'late', 'restic' => 'ok', 'notes' => 'ok']), $history[1]);
    }

    public function testSentinelWithoutHistory(): void
    {
        $json = self::fixture();
        unset($json['history']);

        self::assertSame([], $this->client(json_encode($json, \JSON_THROW_ON_ERROR))->status(self::URL, 'sentinel')->history);
    }

    public function testLastResticRun(): void
    {
        self::assertEquals(new ResticRun(
            new \DateTimeImmutable('2026-10-20T03:15:01+02:00'),
            new \DateTimeImmutable('2026-10-20T03:51:41+02:00'),
            2200, 0, 2139, 110080000, 493, 6101, 66834, 471063000000, '68e6e1036eba86c0', 0, 28,
        ), $this->client()->status(self::URL, 'sentinel')->restic);
    }

    public function testResticRunWithoutSummaryNorCopy(): void
    {
        $json = self::fixture();
        $json['restic'] = ['started_at' => '2026-10-20T03:15:01+02:00', 'finished_at' => '2026-10-20T03:16:00+02:00', 'duration_seconds' => 59, 'rc' => 3, 'backup' => ['rc' => 3, 'seconds' => 58], 'b2_copy' => ['rc' => null, 'seconds' => null]];

        $run = $this->client(json_encode($json, \JSON_THROW_ON_ERROR))->status(self::URL, 'sentinel')->restic;

        self::assertSame([3, null, null], [$run?->rc, $run?->dataAdded, $run?->copyRc]);
    }

    public function testSentinelWithoutResticStatus(): void
    {
        $json = self::fixture();
        $json['restic'] = null;

        self::assertNull($this->client(json_encode($json, \JSON_THROW_ON_ERROR))->status(self::URL, 'sentinel')->restic);
    }

    public function testNotASentinelReport(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('pas un statut de sentinelle');

        $this->client('{"hello": "world"}')->status(self::URL, 'sentinel');
    }

    public function testSentinelNeverReported(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Aucun rapport de la sentinelle');

        (new BackupStatusClient(new HealthchecksClient(HealthchecksClientTest::httpClient(['sentinel' => []]), 'cle-hc')))->status(self::URL, 'sentinel');
    }
}
