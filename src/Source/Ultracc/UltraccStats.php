<?php

namespace App\Source\Ultracc;

/**
 * Stockage (octets) et trafic du mois d'un service Ultra.cc.
 */
final readonly class UltraccStats
{
    public function __construct(
        public int $usedBytes,
        public int $totalBytes,
        public int $freeBytes,
        public float $trafficUsedPercent,
        public \DateTimeImmutable $lastTrafficReset,
        public \DateTimeImmutable $nextTrafficReset,
    ) {
    }
}
