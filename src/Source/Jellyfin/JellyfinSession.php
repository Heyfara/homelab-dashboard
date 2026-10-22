<?php

namespace App\Source\Jellyfin;

/**
 * Une lecture en cours sur le serveur Jellyfin.
 */
final readonly class JellyfinSession
{
    public function __construct(
        public string $id,
        public string $user,
        /** Application : « Jellyfin Web », « Android TV »… */
        public string $client,
        /** Appareil : « Firefox », « Salon »… */
        public string $device,
        public JellyfinItem $item,
        /** secondes écoulées */
        public int $position,
        /** durée totale en secondes, 0 si inconnue */
        public int $duration,
        public bool $paused,
        /** le serveur convertit le flux (charge processeur) */
        public bool $transcoding,
    ) {
    }
}
