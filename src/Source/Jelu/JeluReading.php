<?php

namespace App\Source\Jelu;

/** Un livre en cours ou terminé par un membre de la famille. */
final readonly class JeluReading
{
    public function __construct(
        /** Nom de connexion Jelu du lecteur */
        public string $login,
        public string $bookId,
        public string $title,
        /** Auteurs séparés par des virgules */
        public ?string $authors,
        /** Nom du fichier de couverture, servi sans connexion par Jelu sous /files/ */
        public ?string $image,
        /** Avancement saisi dans Jelu, en % */
        public ?int $percentRead,
        /** Début de la lecture en cours, ou fin pour un livre terminé */
        public ?\DateTimeImmutable $date,
    ) {
    }
}
