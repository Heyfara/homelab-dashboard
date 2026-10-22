<?php

namespace App\Source\DocFetcher;

/** Bilan d'un site pendant un passage. */
final readonly class DocFetcherSite
{
    /**
     * @param list<ImportedDocument> $imported
     * @param list<string>           $errors
     */
    public function __construct(
        public string $name,
        /** ok, failed ou action */
        public string $state,
        /** Documents listés sur le site ; null si la connexion a échoué */
        public ?int $found,
        /** Documents absents de Paperless avant le passage */
        public ?int $missing,
        public array $imported,
        public array $errors,
    ) {
    }
}
