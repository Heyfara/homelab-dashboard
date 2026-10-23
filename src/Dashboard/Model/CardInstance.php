<?php

namespace App\Dashboard\Model;

/**
 * Une carte placée dans une section. Les clés réservées du YAML (type, id, title,
 * span, refresh) sont des propriétés ; toutes les autres vont dans $options,
 * validées par le type de carte.
 */
final readonly class CardInstance
{
    public const RESERVED_KEYS = ['type', 'id', 'title', 'span', 'rows', 'refresh'];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $title,
        /** Largeur, en colonnes */
        public int $span,
        public int $refresh,
        public array $options,
        /** Hauteur, en rangées (une rangée = la hauteur d'une carte service) */
        public int $rows = 1,
        /** Profil où la carte est placée (« Ma config » de ce profil, option credentials: profile) */
        public ?string $profile = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): self
    {
        return new self($this->id, $this->type, $this->title, $this->span, $this->refresh, $options, $this->rows, $this->profile);
    }
}
