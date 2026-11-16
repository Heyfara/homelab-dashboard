<?php

namespace App\Source\Paperless;

/** Champ personnalisé de Paperless. */
final readonly class PaperlessCustomField
{
    public function __construct(
        public int $id,
        public string $name,
        /** string, date, monetary, … */
        public string $dataType,
        /** Devise d'un champ monetary quand la valeur n'en a pas */
        public ?string $defaultCurrency = null,
    ) {
    }
}
