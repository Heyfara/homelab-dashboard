<?php

namespace App\Source\Immich;

final readonly class ImmichAsset
{
    public function __construct(
        public string $id,
        public bool $video,
        /** Heure locale de la prise de vue (sans fuseau : Immich la donne telle qu'affichée sur l'appareil) */
        public ?\DateTimeImmutable $takenAt,
    ) {
    }
}
