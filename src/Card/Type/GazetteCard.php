<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Gazette\GazetteClient;
use App\Source\Gazette\GazetteIssue;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Gazette familiale : jours depuis le dernier envoi (signalé au-delà de
 * warn_after_days), derniers numéros envoyés et numéro en préparation avec les
 * notes de son carnet. Chaque numéro ouvre sa page dans la gazette.
 *
 *     - type: gazette
 *       service: gazette
 *       rows: 2
 *       refresh: 3600
 */
#[AsCard('gazette')]
final readonly class GazetteCard implements CardTypeInterface
{
    private const TIMEZONE = 'Europe/Paris';

    public function __construct(
        private GazetteClient $gazette,
        /** Date du jour (AAAA-MM-JJ), pour les tests ; sinon aujourd'hui à Paris */
        private ?string $today = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setDefault('limit', 3)
            ->setAllowedTypes('limit', 'int')
            ->setAllowedValues('limit', static fn (int $n): bool => $n >= 1 && $n <= 12)
            ->setInfo('limit', 'derniers numéros envoyés affichés')
            ->setDefault('warn_after_days', 35)
            ->setAllowedTypes('warn_after_days', 'int')
            ->setAllowedValues('warn_after_days', static fn (int $n): bool => $n >= 1)
            ->setInfo('warn_after_days', 'au-delà, le nombre de jours depuis le dernier envoi passe en alerte')
            ->setDefault('show_draft', true)
            ->setAllowedTypes('show_draft', 'bool')
            ->setInfo('show_draft', 'afficher le numéro en préparation et ses notes');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return ['title' => $card->title ?? $service->name, 'service' => $service->name, 'url' => $service->url, 'icon' => $service->icon];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];
        $public = rtrim((string) $service->url, '/');
        $issues = $this->gazette->issues($service->check ?? $public);
        $link = static fn (GazetteIssue $i): string => \sprintf('%s/numeros/%s', $public, rawurlencode($i->id));

        $sent = array_values(array_filter($issues, static fn (GazetteIssue $i): bool => $i->sent()));
        usort($sent, static fn (GazetteIssue $a, GazetteIssue $b): int => $b->sentAt <=> $a->sentAt);

        $daysSince = null;
        if ([] !== $sent) {
            $zone = new \DateTimeZone(self::TIMEZONE);
            $today = new \DateTimeImmutable($this->today ?? 'today', $zone);
            // Jours calendaires à Paris : envoyée hier soir = J+1, quelle que soit l'heure
            $sentDay = ($sent[0]->sentAt ?? $today)->setTimezone($zone)->setTime(0, 0);
            $daysSince = (int) $sentDay->diff($today->setTime(0, 0))->format('%r%a');
        }

        $draft = null;
        if ($card->options['show_draft']) {
            $pending = array_filter($issues, static fn (GazetteIssue $i): bool => !$i->sent());
            usort($pending, static fn (GazetteIssue $a, GazetteIssue $b): int => $b->number <=> $a->number);
            $draft = isset($pending[0]) ? [
                'number' => $pending[0]->number,
                'title' => $pending[0]->title,
                'month' => $pending[0]->month,
                'status' => $pending[0]->status,
                'notes' => $pending[0]->notes,
                'link' => $link($pending[0]),
            ] : null;
        }

        return [
            'daysSince' => $daysSince,
            'late' => null !== $daysSince && $daysSince > $card->options['warn_after_days'],
            'warnAfterDays' => $card->options['warn_after_days'],
            'sent' => array_map(static fn (GazetteIssue $i): array => [
                'number' => $i->number,
                'title' => $i->title,
                'month' => $i->month,
                'sentAt' => $i->sentAt?->format(\DateTimeInterface::ATOM),
                'link' => $link($i),
            ], \array_slice($sent, 0, $card->options['limit'])),
            'draft' => $draft,
        ];
    }
}
