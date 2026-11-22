<?php

namespace App\Source\Paperless;

/** Document de Paperless, réduit aux champs lus par le dashboard. */
final readonly class PaperlessDocument
{
    public function __construct(
        public int $id,
        public string $title,
        /** Date du document (AAAA-MM-JJ) */
        public ?string $created,
        /** Id du correspondant */
        public ?int $correspondent,
        /** @var array<int, mixed> valeur de chaque champ personnalisé posé sur le document, par id du champ */
        public array $customFields,
    ) {
    }
}
