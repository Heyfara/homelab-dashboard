<?php

namespace App\Source\DocFetcher;

/** Document envoyé à Paperless, avec le titre et la date qu'il y porte. */
final readonly class ImportedDocument
{
    public function __construct(
        public int $id,
        public string $title,
        /** Date de création (AAAA-MM-JJ) */
        public ?string $created,
    ) {
    }
}
