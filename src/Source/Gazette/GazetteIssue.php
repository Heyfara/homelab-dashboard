<?php

namespace App\Source\Gazette;

/** Un numéro de la gazette familiale, tel que le résume sa liste. */
final readonly class GazetteIssue
{
    public function __construct(
        /** Identifiant (8 caractères hexadécimaux), dans l'adresse /numeros/<id> */
        public string $id,
        public int $number,
        /** Vide quand le numéro n'a pas de titre : on affiche alors le mois */
        public string $title,
        /** AAAA-MM */
        public string $month,
        /** brouillon, teste ou envoye */
        public string $status,
        /** Notes du carnet du mois */
        public int $notes,
        public ?\DateTimeImmutable $sentAt,
    ) {
    }

    public function sent(): bool
    {
        return 'envoye' === $this->status && null !== $this->sentAt;
    }
}
