<?php

namespace App\Source\Beszel;

/**
 * Une mesure de la collection « system_stats » : moyenne sur la période de son
 * type (1m, 10m, 20m, 120m, 480m).
 */
final readonly class BeszelStat
{
    /**
     * @param array<string, mixed> $values clés abrégées de Beszel : cpu (%), mp (% mémoire),
     *                                     m / mu (mémoire totale / utilisée, Go), d / du (disque, Go),
     *                                     b ([envoyé, reçu] octets/s), t (températures °C), efs…
     */
    public function __construct(
        public \DateTimeImmutable $at,
        public array $values,
    ) {
    }
}
