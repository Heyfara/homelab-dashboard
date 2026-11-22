<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Paperless\PaperlessClient;
use App\Source\Paperless\PaperlessCustomField;
use App\Source\Paperless\PaperlessDocument;
use App\Source\Paperless\PaperlessMonetary;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Factures pas encore payées, d'après les champs personnalisés des documents
 * Paperless (montant, échéance, date de paiement), triées par échéance. Un job
 * séparé remplit ces champs ; la carte ne fait que lire Paperless.
 *
 *     - type: factures
 *       service: paperless
 *       span: 2
 *       rows: 2
 *       refresh: 3600
 */
#[AsCard('factures')]
final readonly class FacturesCard implements CardTypeInterface
{
    public function __construct(private PaperlessClient $paperless)
    {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setInfo('service', 'service Paperless du catalogue (check = adresse de l\'API, url = liens)')
            ->setDefault('document_type', 'Facture')
            ->setAllowedTypes('document_type', 'string')
            ->setInfo('document_type', 'type de document des factures dans Paperless')
            ->setDefault('soon_days', 7)
            ->setAllowedTypes('soon_days', 'int')
            ->setAllowedValues('soon_days', static fn (int $n): bool => $n >= 0)
            ->setInfo('soon_days', 'échéance proche (en ambre) à ce nombre de jours ou moins')
            ->setOptions('fields', static function (OptionsResolver $fields): void {
                $fields
                    ->setDefaults(['amount' => 'Montant', 'due' => 'Échéance', 'paid' => 'Payée le'])
                    ->setAllowedTypes('amount', 'string')
                    ->setAllowedTypes('due', 'string')
                    ->setAllowedTypes('paid', 'string');
            })
            ->setInfo('fields', 'noms des champs personnalisés Paperless : amount (monétaire), due et paid (dates)');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return ['title' => $card->title ?? 'Factures à payer', 'url' => $service->url, 'soonDays' => $card->options['soon_days']];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];
        $api = $service->check ?? (string) $service->url;
        $public = rtrim((string) $service->url, '/');
        $names = $card->options['fields'];

        $fields = [];
        foreach ($this->paperless->customFields($api) as $field) {
            $fields[$field->name] = $field;
        }
        $amountField = $fields[$names['amount']] ?? null;
        $dueField = $fields[$names['due']] ?? null;
        $paidField = $fields[$names['paid']] ?? null;

        // Non payée : le champ « payée » n'est pas posé, ou posé sans date.
        // Sans ce champ dans Paperless, aucune facture n'est marquée payée.
        $unpaid = null === $paidField ? null : ['OR', [[$paidField->id, 'exists', false], [$paidField->id, 'isnull', true]]];
        $documents = $this->paperless->documents($api, $card->options['document_type'], $unpaid);

        $correspondents = [] === array_filter($documents, static fn (PaperlessDocument $d): bool => null !== $d->correspondent)
            ? []
            : $this->paperless->correspondents($api);

        $invoices = array_map(static function (PaperlessDocument $d) use ($amountField, $dueField, $correspondents, $public): array {
            $amount = self::value($d, $amountField);
            $money = null === $amount ? null : PaperlessMonetary::parse($amount, $amountField?->defaultCurrency);
            $due = self::value($d, $dueField);

            return [
                'id' => $d->id,
                'title' => $d->title,
                'correspondent' => null === $d->correspondent ? null : ($correspondents[$d->correspondent] ?? null),
                'amount' => null === $money ? null : round($money->cents / 100, 2),
                'currency' => $money?->currency ?? $amountField?->defaultCurrency ?? 'EUR',
                'dueDate' => \is_string($due) && preg_match('/^\d{4}-\d{2}-\d{2}/', $due) ? substr($due, 0, 10) : null,
                'created' => $d->created,
                'url' => \sprintf('%s/documents/%d/details', $public, $d->id),
                'cents' => $money?->cents,
            ];
        }, $documents);

        // Échéance croissante, sans échéance à la fin ; à égalité, la plus ancienne facture d'abord.
        $key = static fn (array $i): array => [null === $i['dueDate'], $i['dueDate'], $i['created'] ?? '', $i['id']];
        usort($invoices, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        // Total dans la devise la plus courante ; les autres devises n'y entrent pas.
        $known = array_filter($invoices, static fn (array $i): bool => null !== $i['cents']);
        $counts = array_count_values(array_column($known, 'currency'));
        arsort($counts);
        $currency = (string) (array_key_first($counts) ?? $amountField?->defaultCurrency ?? 'EUR');
        $cents = array_sum(array_map(static fn (array $i): int => $i['currency'] === $currency ? $i['cents'] : 0, $known));

        return [
            'invoices' => array_map(static function (array $i): array {
                unset($i['cents']);

                return $i;
            }, $invoices),
            'total' => round($cents / 100, 2),
            'currency' => $currency,
            'count' => \count($invoices),
            'missingAmounts' => \count($invoices) - \count($known),
        ];
    }

    private static function value(PaperlessDocument $document, ?PaperlessCustomField $field): mixed
    {
        return null === $field ? null : ($document->customFields[$field->id] ?? null);
    }
}
