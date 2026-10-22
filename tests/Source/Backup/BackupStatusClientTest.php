<?php

namespace App\Tests\Source\Backup;

use App\Source\Backup\BackupEntry;
use App\Source\Backup\BackupRun;
use App\Source\Backup\ResticRun;
use App\Source\Backup\BackupStatusClient;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BackupStatusClientTest extends TestCase
{
    private const URL = 'http://statut:3000/images/backup-status.json';

    private function client(?MockResponse $response = null): BackupStatusClient
    {
        return new BackupStatusClient(new MockHttpClient($response ?? self::homelab()));
    }

    /** Statut d'une sentinelle fictive, réutilisé par FakeHomelab. */
    public static function homelab(): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/backup-status.json'), ['response_headers' => ['Content-Type' => 'application/json']]);
    }

    public function testStatus(): void
    {
        $status = $this->client()->status(self::URL);

        self::assertEquals(new \DateTimeImmutable('2026-10-20T08:00:01+02:00'), $status->checkedAt);
        self::assertCount(4, $status->entries);
        self::assertEquals(new BackupEntry('cuisine', 'ok', 5.0, 30.0, 'cuisine-20261020-030001.tar.gz', null), $status->entries[0]);
        self::assertEquals(new BackupEntry('notes', 'unreachable', null, 30.0, null, 'dossier inaccessible (beta down ou NFS bloqué)'), $status->entries[3]);
    }

    public function testHistoryOldestFirst(): void
    {
        $history = $this->client()->status(self::URL)->history;

        self::assertCount(3, $history);
        self::assertEquals(new BackupRun(new \DateTimeImmutable('2026-10-19T08:00:01+02:00'), ['cuisine' => 'ok', 'galerie' => 'late', 'restic' => 'ok', 'notes' => 'ok']), $history[1]);
    }

    public function testSentinelWithoutHistory(): void
    {
        $json = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/backup-status.json'), true);
        unset($json['history']);

        self::assertSame([], $this->client(new MockResponse(json_encode($json, \JSON_THROW_ON_ERROR)))->status(self::URL)->history);
    }

    public function testLastResticRun(): void
    {
        self::assertEquals(new ResticRun(
            new \DateTimeImmutable('2026-10-20T03:15:01+02:00'),
            new \DateTimeImmutable('2026-10-20T03:51:41+02:00'),
            2200, 0, 2139, 110080000, 493, 6101, 66834, 471063000000, '68e6e1036eba86c0', 0, 28,
        ), $this->client()->status(self::URL)->restic);
    }

    public function testResticRunWithoutSummaryNorCopy(): void
    {
        $json = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/backup-status.json'), true);
        $json['restic'] = ['started_at' => '2026-10-20T03:15:01+02:00', 'finished_at' => '2026-10-20T03:16:00+02:00', 'duration_seconds' => 59, 'rc' => 3, 'backup' => ['rc' => 3, 'seconds' => 58], 'b2_copy' => ['rc' => null, 'seconds' => null]];

        $run = $this->client(new MockResponse(json_encode($json, \JSON_THROW_ON_ERROR)))->status(self::URL)->restic;

        self::assertSame([3, null, null], [$run?->rc, $run?->dataAdded, $run?->copyRc]);
    }

    public function testSentinelWithoutResticStatus(): void
    {
        $json = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/backup-status.json'), true);
        $json['restic'] = null;

        self::assertNull($this->client(new MockResponse(json_encode($json, \JSON_THROW_ON_ERROR)))->status(self::URL)->restic);
    }

    public function testNotASentinelFile(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('pas un statut de sentinelle');

        $this->client(new MockResponse('{"hello": "world"}'))->status(self::URL);
    }

    public function testMissingFile(): void
    {
        $this->expectExceptionMessage('Statut des sauvegardes injoignable');

        $this->client(new MockResponse('', ['http_code' => 404]))->status(self::URL);
    }
}
