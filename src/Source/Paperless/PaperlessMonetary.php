<?php

namespace App\Source\Paperless;

/**
 * Valeur d'un champ monetary : l'API renvoie « EUR246.50 », parfois sans devise
 * (« 246.50 » : devise par défaut du champ) ou un nombre (anciennes versions).
 */
final readonly class PaperlessMonetary
{
    public function __construct(
        /** En centimes, pour additionner sans erreur d'arrondi */
        public int $cents,
        /** Code ISO 4217 */
        public string $currency,
    ) {
    }

    public static function parse(mixed $value, ?string $defaultCurrency = null): ?self
    {
        $currency = $defaultCurrency ?? 'EUR';
        if (\is_int($value) || \is_float($value)) {
            return new self((int) round($value * 100), $currency);
        }
        if (!\is_string($value) || !preg_match('/^([A-Z]{3})?(-?\d+(?:\.\d+)?)$/', trim($value), $m)) {
            return null;
        }

        return new self((int) round((float) $m[2] * 100), '' !== $m[1] ? $m[1] : $currency);
    }
}
