<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Source\DocFetcher\DocFetcherRun;
use App\Source\DocFetcher\DocFetcherStatusReader;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Imports automatiques de documents dans Paperless (doc-fetcher) : dernier
 * passage, état de chaque site, historique des passages et derniers documents
 * importés. Signale un cron qui ne passe plus, même si on a lancé le script à
 * la main entre-temps.
 *
 *     - type: doc-fetcher
 *       file: /data/doc-fetcher/status.json
 *       service: paperless                # liens vers les documents
 *       labels: { impots: Impôts }        # nom du site → nom affiché
 *       span: 2
 *       rows: 2
 */
#[AsCard('doc-fetcher')]
final readonly class DocFetcherCard implements CardTypeInterface
{
    public function __construct(
        private DocFetcherStatusReader $reader,
        /** Instant présent (ISO 8601), pour les tests ; sinon maintenant */
        private ?string $now = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $resolver
            ->setRequired('file')
            ->setAllowedTypes('file', 'string')
            ->setInfo('file', 'chemin du status.json de doc-fetcher, monté dans le container')
            ->setDefault('service', null)
            ->setAllowedTypes('service', ['null', 'string'])
            ->setAllowedValues('service', static fn (?string $s): bool => null === $s || isset($dashboard->services[$s]))
            ->setInfo('service', 'service Paperless du catalogue, pour les liens vers les documents')
            ->setDefault('labels', [])
            ->setAllowedTypes('labels', 'string[]')
            ->setInfo('labels', 'nom du site → nom affiché ; sinon le nom avec une majuscule')
            ->setDefault('stale_days', 8)
            ->setAllowedTypes('stale_days', 'int')
            ->setInfo('stale_days', 'au-delà, le passage automatique est signalé en retard')
            ->setDefault('documents', 6)
            ->setAllowedTypes('documents', 'int')
            ->setInfo('documents', 'nombre de documents importés listés');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return ['title' => $card->title ?? 'Imports Paperless'];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $status = $this->reader->status($card->options['file']);
        $now = new \DateTimeImmutable($this->now ?? 'now');
        $label = static fn (string $name): string => $card->options['labels'][$name] ?? mb_ucfirst($name);
        $paperless = null === $card->options['service'] ? null : rtrim((string) $dashboard->services[$card->options['service']]->url, '/');

        $lastAuto = array_find($status->runs, static fn (DocFetcherRun $r): bool => !$r->manual);
        $last = $status->runs[0] ?? null;

        // Dernier état connu de chaque site : un passage --site ne touche que le sien.
        $sites = [];
        $documents = [];
        foreach ($status->runs as $run) {
            foreach ($run->sites as $site) {
                $sites[$site->name] ??= [
                    'name' => $site->name,
                    'label' => $label($site->name),
                    'state' => $site->state,
                    'checkedAt' => $run->startedAt->format(\DATE_ATOM),
                    'found' => $site->found,
                    'missing' => $site->missing,
                    'errors' => $site->errors,
                ];
                foreach ($site->imported as $doc) {
                    $documents[] = [
                        'id' => $doc->id,
                        'title' => $doc->title,
                        'created' => $doc->created,
                        'site' => $label($site->name),
                        'importedAt' => $run->startedAt->format(\DATE_ATOM),
                        'url' => null === $paperless ? null : \sprintf('%s/documents/%d/details', $paperless, $doc->id),
                    ];
                }
            }
        }
        $sites = array_values($sites);
        usort($sites, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return [
            'schedule' => $status->schedule,
            'lastRun' => null === $last ? null : [
                'startedAt' => $last->startedAt->format(\DATE_ATOM),
                'finishedAt' => $last->finishedAt?->format(\DATE_ATOM),
                'state' => $last->state,
                'manual' => $last->manual,
            ],
            'stale' => null !== $lastAuto && $now->getTimestamp() - $lastAuto->startedAt->getTimestamp() > $card->options['stale_days'] * 86400,
            'sites' => $sites,
            'history' => array_reverse(array_map(static fn (DocFetcherRun $r): array => [
                'startedAt' => $r->startedAt->format(\DATE_ATOM),
                'state' => $r->state,
                'manual' => $r->manual,
                'imported' => array_sum(array_map(static fn ($s): int => \count($s->imported), $r->sites)),
            ], $status->runs)),
            'importedTotal' => \count($documents),
            'documents' => \array_slice($documents, 0, $card->options['documents']),
        ];
    }
}
