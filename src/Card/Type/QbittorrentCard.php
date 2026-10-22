<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Qbittorrent\QbittorrentClient;
use App\Source\Qbittorrent\QbittorrentTorrent;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Totaux reçus/envoyés de qBittorrent, débits actuels et derniers téléchargements.
 *
 *     - type: qbittorrent
 *       service: qbittorrent
 *       sort: added          # ou completed : derniers terminés
 *       span: 2
 *       rows: 2
 */
#[AsCard('qbittorrent')]
final readonly class QbittorrentCard implements CardTypeInterface
{
    public function __construct(
        private QbittorrentClient $qbittorrent,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setDefault('limit', 5)
            ->setAllowedTypes('limit', 'int')
            ->setAllowedValues('limit', static fn (int $n): bool => $n >= 1 && $n <= 20)
            ->setDefault('sort', 'added')
            ->setAllowedValues('sort', ['added', 'completed']);
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return [
            'title' => $card->title ?? 'Téléchargements',
            'service' => $service->name,
            'url' => $service->url,
            'icon' => $service->icon,
        ];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];
        $snapshot = $this->qbittorrent->snapshot($service->check ?? (string) $service->url);

        $torrents = $snapshot->torrents;
        if ('completed' === $card->options['sort']) {
            $torrents = array_filter($torrents, static fn (QbittorrentTorrent $t): bool => null !== $t->completedOn);
            usort($torrents, static fn (QbittorrentTorrent $a, QbittorrentTorrent $b): int => $b->completedOn <=> $a->completedOn);
        } else {
            usort($torrents, static fn (QbittorrentTorrent $a, QbittorrentTorrent $b): int => $b->addedOn <=> $a->addedOn);
        }

        return [
            'totals' => [
                'downloaded' => $snapshot->downloaded,
                'uploaded' => $snapshot->uploaded,
                'ratio' => $snapshot->ratio,
                'downloadSpeed' => $snapshot->downloadSpeed,
                'uploadSpeed' => $snapshot->uploadSpeed,
            ],
            'torrents' => array_map(static fn (QbittorrentTorrent $t): array => [
                'name' => $t->name,
                'size' => $t->size,
                'progress' => $t->progress,
                'status' => self::status($t->state),
                'addedAt' => (new \DateTimeImmutable('@'.$t->addedOn))->format(\DATE_ATOM),
                'completedAt' => null !== $t->completedOn ? (new \DateTimeImmutable('@'.$t->completedOn))->format(\DATE_ATOM) : null,
            ], \array_slice(array_values($torrents), 0, $card->options['limit'])),
        ];
    }

    /**
     * États de qBittorrent 4 et 5 (paused* devenus stopped* en 5.0) ramenés à
     * ce que la carte affiche.
     */
    private static function status(string $state): string
    {
        return match ($state) {
            'downloading', 'forcedDL', 'metaDL', 'forcedMetaDL' => 'downloading',
            'stalledDL' => 'stalled',
            'queuedDL', 'queuedUP' => 'queued',
            'uploading', 'stalledUP', 'forcedUP' => 'seeding',
            'pausedDL', 'stoppedDL' => 'paused',
            'pausedUP', 'stoppedUP' => 'done',
            'checkingDL', 'checkingUP', 'checkingResumeData', 'moving', 'allocating' => 'checking',
            'error', 'missingFiles' => 'error',
            default => 'unknown',
        };
    }
}
