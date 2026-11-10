<?php

namespace App\Source\Ultracc;

/**
 * Stockage (Gio, comme `quota -s`) et trafic du mois d'un service Ultra.cc.
 */
final readonly class UltraccStats
{
    public function __construct(
        public float $usedGiB,
        public float $totalGiB,
        public float $freeGiB,
        public float $trafficUsedPercent,
        public \DateTimeImmutable $lastTrafficReset,
        public \DateTimeImmutable $nextTrafficReset,
    ) {
    }
}
