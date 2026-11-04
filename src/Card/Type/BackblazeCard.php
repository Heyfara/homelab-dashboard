<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\History\DailyHistory;
use App\Source\Backblaze\B2Bucket;
use App\Source\Backblaze\B2Client;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Stockage Backblaze B2 : taille et dernier envoi de chaque bucket, et coût
 * mensuel ESTIMÉ (B2 n'expose pas la facturation : taille × tarif). La taille
 * de chaque bucket est relevée une fois par jour (DailyHistory, série
 * « backblaze ») : l'historique du coût en est recalculé, au tarif de chaque jour.
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
        private DailyHistory $history,
        private ?string $now = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $resolver
            ->setDefault('buckets', [])
            ->setAllowedTypes('buckets', 'string[]')
            ->setInfo('buckets', 'buckets à afficher ; vide = tous ceux que la clé voit')
            // Tarifs publics de B2 : 6 $ depuis le 2023-10-03, 6,95 $ depuis le 2026-10-01.
            ->setDefault('price_per_tb', ['2023-10-03' => 6, '2026-10-01' => 6.95])
            ->setAllowedTypes('price_per_tb', ['int', 'float', 'array'])
            ->setAllowedValues('price_per_tb', static fn (int|float|array $p): bool => null !== self::prices($p))
            ->setNormalizer('price_per_tb', static fn (OptionsResolver $r, int|float|array $p): array => self::prices($p) ?? [])
            ->setInfo('price_per_tb', 'tarif du stockage par To et par mois ; ou tarifs datés { AAAA-MM-JJ: tarif } (en vigueur à partir de cette date)')
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
            ->setInfo('stale_hours', 'au-delà, le dernier envoi est signalé (sauvegarde manquée)')
            ->setDefault('history_days', 365)
            ->setAllowedTypes('history_days', 'int')
            ->setAllowedValues('history_days', static fn (int $d): bool => $d > 0)
            ->setInfo('history_days', 'jours d\'historique du coût renvoyés');
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
        $this->history->record('backblaze', array_column($rows, 'bytes', 'name'));

        $today = new \DateTimeImmutable($this->now ?? 'now');
        $bytes = array_sum(array_column($rows, 'bytes'));
        $price = self::priceOn($o['price_per_tb'], $today->format('Y-m-d'));

        return [
            'buckets' => $rows,
            'total' => ['bytes' => $bytes, 'files' => array_sum(array_column($rows, 'files'))],
            'cost' => [
                // Ce qui est réellement payé : TVA comprise si elle est configurée.
                'monthly' => self::cost($bytes, $price, $o, true),
                'monthlyExclVat' => self::cost($bytes, $price, $o, false),
                'vat' => (float) $o['vat'],
                'pricePerTb' => $price,
                'freeGb' => (float) $o['free_gb'],
                'currency' => $o['currency'],
                'prices' => array_map(static fn (string $from, float $p): array => ['from' => $from, 'pricePerTb' => $p], array_keys($o['price_per_tb']), $o['price_per_tb']),
            ],
            'history' => $this->costHistory($o, $today),
        ];
    }

    /**
     * Coût de chaque jour relevé : taille des buckets de la carte, au tarif du jour.
     *
     * @param array<string, mixed> $o
     *
     * @return list<array{date: string, bytes: int, monthly: float, pricePerTb: float}>
     */
    private function costHistory(array $o, \DateTimeImmutable $today): array
    {
        $since = $today->modify(\sprintf('-%d days', $o['history_days']))->format('Y-m-d');
        $history = [];
        foreach ($this->history->read('backblaze') as $date => $sizes) {
            $date = (string) $date;
            if ($date <= $since) {
                continue;
            }
            if ([] !== $o['buckets']) {
                $sizes = array_intersect_key($sizes, array_flip($o['buckets']));
            }
            if ([] === $sizes) {
                continue;
            }
            $bytes = (int) array_sum($sizes);
            $price = self::priceOn($o['price_per_tb'], $date);
            $history[] = ['date' => $date, 'bytes' => $bytes, 'monthly' => self::cost($bytes, $price, $o, true), 'pricePerTb' => $price];
        }

        return $history;
    }

    /**
     * @param array<string, mixed> $o
     */
    private static function cost(int $bytes, float $pricePerTb, array $o, bool $withVat): float
    {
        // B2 facture au Go décimal ; les premiers Go sont offerts.
        $billableTb = max(0, $bytes / 1e9 - $o['free_gb']) / 1000;

        return round($billableTb * $pricePerTb * ($withVat ? 1 + $o['vat'] / 100 : 1), 2);
    }

    /**
     * @param array<string, float> $prices tarifs datés, triés
     */
    private static function priceOn(array $prices, string $date): float
    {
        $price = reset($prices);
        foreach ($prices as $from => $p) {
            if ((string) $from <= $date) {
                $price = $p;
            }
        }

        return (float) $price;
    }

    /**
     * Tarif seul ou tarifs datés → tarifs datés triés ; null si invalide.
     *
     * @param int|float|array<mixed> $value
     *
     * @return ?array<string, float>
     */
    private static function prices(int|float|array $value): ?array
    {
        if (!\is_array($value)) {
            $value = ['1970-01-01' => $value];
        }
        $prices = [];
        foreach ($value as $from => $price) {
            // Clé date en YAML bloc (2026-10-01:) : Symfony Yaml en fait un timestamp.
            $from = \is_int($from) ? gmdate('Y-m-d', $from) : $from;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
            if (false === $date || $date->format('Y-m-d') !== $from || !\is_int($price) && !\is_float($price) || $price < 0) {
                return null;
            }
            $prices[$from] = (float) $price;
        }
        ksort($prices);

        return [] === $prices ? null : $prices;
    }
}
