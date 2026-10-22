<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Source\Backup\BackupEntry;
use App\Source\Backup\BackupRun;
use App\Source\Backup\BackupStatusClient;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Fraîcheur des sauvegardes, d'après le statut de la sentinelle du homelab :
 * bilan des sauvegardes et dernier passage de restic (durée, volume ajouté). Les âges sont
 * recalculés à l'affichage, et la carte signale une sentinelle qui ne passe plus.
 *
 *     - type: backups
 *       url: http://10.0.0.16:3000/images/backup-status.json
 *       aliases: { bookstack: wiki, cwa: ebooks }   # nom sentinelle → service du catalogue
 *       span: 2
 *       rows: 2
 */
#[AsCard('backups')]
final readonly class BackupsCard implements CardTypeInterface
{
    /** Ordre d'affichage : le plus grave d'abord */
    private const SEVERITY = ['unreachable' => 0, 'missing' => 1, 'late' => 2, 'ok' => 3];

    public function __construct(
        private BackupStatusClient $client,
        /** Instant présent (ISO 8601), pour les tests ; sinon maintenant */
        private ?string $now = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $services = array_map('strval', array_keys($dashboard->services));

        $resolver
            ->setRequired('url')
            ->setAllowedTypes('url', 'string')
            ->setInfo('url', 'adresse du backup-status.json publié par la sentinelle')
            ->setDefault('aliases', [])
            ->setAllowedTypes('aliases', 'string[]')
            ->setAllowedValues('aliases', static fn (array $a): bool => [] === array_diff($a, $services))
            ->setInfo('aliases', 'nom dans la sentinelle → service du catalogue (nom affiché), quand ils diffèrent')
            ->setDefault('stale_hours', 26)
            ->setAllowedTypes('stale_hours', 'int')
            ->setInfo('stale_hours', 'au-delà, la sentinelle elle-même est signalée en retard (elle passe une fois par jour)');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return ['title' => $card->title ?? 'Sauvegardes'];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $status = $this->client->status($card->options['url']);
        $now = new \DateTimeImmutable($this->now ?? 'now');
        $sinceCheck = ($now->getTimestamp() - $status->checkedAt->getTimestamp()) / 3600;

        $label = static fn (string $name): string => ($dashboard->services[$card->options['aliases'][$name] ?? $name] ?? null)->name ?? ucfirst($name);

        $entries = array_map(static function (BackupEntry $e) use ($label, $sinceCheck): array {
            return [
                'name' => $e->name,
                'label' => $label($e->name),
                'state' => $e->state,
                'ageHours' => null === $e->ageHours ? null : round($e->ageHours + $sinceCheck, 1),
                'maxHours' => $e->maxHours,
                'latest' => $e->latest,
                'reason' => $e->reason,
            ];
        }, $status->entries);
        usort($entries, static fn (array $a, array $b): int => [self::SEVERITY[$a['state']] ?? 0, mb_strtolower($a['label'])] <=> [self::SEVERITY[$b['state']] ?? 0, mb_strtolower($b['label'])]);
        $ok = \count(array_filter($entries, static fn (array $e): bool => 'ok' === $e['state']));

        return [
            'checkedAt' => $status->checkedAt->format(\DATE_ATOM),
            'stale' => $sinceCheck > $card->options['stale_hours'],
            'ok' => $ok,
            'problems' => \count($entries) - $ok,
            'total' => \count($entries),
            'entries' => $entries,
            'restic' => null === $status->restic ? null : [
                'finishedAt' => $status->restic->finishedAt->format(\DATE_ATOM),
                'durationSeconds' => $status->restic->durationSeconds,
                'rc' => $status->restic->rc,
                'backupSeconds' => $status->restic->backupSeconds,
                'dataAdded' => $status->restic->dataAdded,
                'filesNew' => $status->restic->filesNew,
                'filesChanged' => $status->restic->filesChanged,
                'filesProcessed' => $status->restic->filesProcessed,
                'bytesProcessed' => $status->restic->bytesProcessed,
                'copyRc' => $status->restic->copyRc,
                'copySeconds' => $status->restic->copySeconds,
            ],
            // Une nuit par relevé gardé par la sentinelle (vide si elle n'en garde pas).
            'nights' => array_map(static function (BackupRun $run) use ($label): array {
                $problems = array_map($label, array_keys(array_filter($run->states, static fn (string $state): bool => 'ok' !== $state)));
                sort($problems, \SORT_FLAG_CASE | \SORT_STRING);

                return ['date' => $run->checkedAt->format('Y-m-d'), 'problems' => $problems];
            }, $status->history),
        ];
    }
}
