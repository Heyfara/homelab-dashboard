<?php

namespace App\Dashboard\Model;

use Symfony\Component\String\UnicodeString;

final readonly class Section
{
    /** Onglet d'une section sans `tab:` */
    public const DEFAULT_TAB = 'Accueil';

    /** Identifiants d'onglet déjà pris par les adresses du front (/<profil>/config, ancienne /<profil>/cles). */
    public const RESERVED_TABS = ['config', 'cles'];

    /**
     * @param list<CardInstance> $cards
     */
    public function __construct(
        public string $title,
        public int $columns,
        public array $cards,
        /** Nom de l'onglet où la section s'affiche */
        public string $tab = self::DEFAULT_TAB,
    ) {
    }

    /** Identifiant d'onglet, dans l'adresse (/<profil>/<onglet>) : « Médias et jeux » → medias-et-jeux. */
    public static function tabSlug(string $tab): string
    {
        // Pas iconv : sous Alpine (musl), « é » y devient « 'e ».
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', (new UnicodeString($tab))->ascii()->lower()->toString()), '-');
    }

    /**
     * @param list<CardInstance> $cards
     */
    public function withCards(array $cards): self
    {
        return new self($this->title, $this->columns, $cards, $this->tab);
    }
}
