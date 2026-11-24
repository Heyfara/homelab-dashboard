<?php

namespace App\Source\Freebox;

/**
 * État de la connexion Internet (`connection`). Débits en octets par seconde.
 */
final readonly class FreeboxConnection
{
    public function __construct(
        /** up, down, going_up, going_down */
        public string $state,
        /** ftth, xdsl, backup_4g… */
        public string $media,
        public int $rateDown,
        public int $rateUp,
        /** Débit maximal de la ligne */
        public int $bandwidthDown,
        public int $bandwidthUp,
    ) {
    }
}
