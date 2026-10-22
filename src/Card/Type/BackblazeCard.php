<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Source\Backblaze\B2Bucket;
use App\Source\Backblaze\B2Client;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Stockage Backblaze B2 : taille et dernier envoi de chaque bucket, et coût
 * mensuel ESTIMÉ (B2 n'expose pas la facturation : taille × tarif).
 *
 *     - type: backblaze
 *       span: 2
 *       rows: 2
 *       refresh: 86400     # une fois par jour, comme les sauvegardes
 */
#[AsCard('backblaze')]
final readonly class BackblazeCard implements CardTypeInterface
{
    public function __construct(
        private B2Client $b2,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $resolver
            ->setDefault('buckets', [])
            ->setAllowedTypes('buckets', 'string[]')
            ->setInfo('buckets', 'buckets à afficher ; vide = tous ceux que la clé voit')
            ->setDefault('price_per_tb', 6.95)
            ->setAllowedTypes('price_per_tb', ['int', 'float'])
            ->setAllowedValues('price_per_tb', static fn (int|float $p): bool => $p >= 0)
            ->setInfo('price_per_tb', 'tarif du stockage par To et par mois (6,95 $ depuis le 2026-10-01)')
            ->setDefault('free_gb', 10)
            ->setAllowedTypes('free_gb', ['int', 'float'])
            ->setAllowedValues('free_gb', static fn (int|float $g): bool => $g >= 0)
            ->setDefault('currency', '$')
            ->setAllowedTypes('currency', 'string')
            ->setDefault('vat', 0)
            ->setAllowedTypes('vat', ['int', 'float'])
            ->setAllowedValues('vat', static fn (int|float $v): bool => $v >= 0 && $v < 100)
            ->setInfo('vat', 'taux de TVA ajouté par Backblaze à la facture, en % (20 en France) ; 0 = prix hors taxes')
            ->setDefault('stale_hours', 36)
            ->setAllowedTypes('stale_hours', 'int')
            ->setInfo('stale_hours', 'au-delà, le dernier envoi est signalé (sauvegarde manquée)');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return [
            'title' => $card->title ?? 'Backblaze B2',
            'url' => 'https://secure.backblaze.com/b2_buckets.htm',
            'staleHours' => $card->options['stale_hours'],
        ];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $o = $card->options;
        $buckets = array_filter($this->b2->buckets(), static fn (B2Bucket $b): bool => [] === $o['buckets'] || \in_array($b->name, $o['buckets'], true));

        $rows = [];
        foreach ($buckets as $bucket) {
            $usage = $this->b2->usage($bucket);
            $rows[] = ['name' => $bucket->name, 'bytes' => $usage->bytes, 'files' => $usage->files, 'lastUpload' => $usage->lastUpload?->format(\DATE_ATOM)];
        }

        $bytes = array_sum(array_column($rows, 'bytes'));
        // B2 facture au Go décimal ; les premiers Go sont offerts.
        $billableTb = max(0, $bytes / 1e9 - $o['free_gb']) / 1000;

        return [
            'buckets' => $rows,
            'total' => ['bytes' => $bytes, 'files' => array_sum(array_column($rows, 'files'))],
            'cost' => [
                // Ce qui est réellement payé : TVA comprise si elle est configurée.
                'monthly' => round($billableTb * $o['price_per_tb'] * (1 + $o['vat'] / 100), 2),
                'monthlyExclVat' => round($billableTb * $o['price_per_tb'], 2),
                'vat' => (float) $o['vat'],
                'pricePerTb' => (float) $o['price_per_tb'],
                'freeGb' => (float) $o['free_gb'],
                'currency' => $o['currency'],
            ],
        ];
    }
}
