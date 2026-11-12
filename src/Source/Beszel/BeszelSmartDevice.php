<?php

namespace App\Source\Beszel;

/**
 * Une fiche de la collection « smart_devices » de Beszel : un disque surveillé
 * par l'agent (smartctl), mise à jour à chaque relevé SMART.
 */
final readonly class BeszelSmartDevice
{
    /** Attributs SMART ATA qui annoncent une usure ou un défaut : id → nom court. */
    public const COUNTERS = [5 => 'reallocated', 197 => 'pending', 198 => 'uncorrectable', 199 => 'crc'];

    /**
     * @param array<string, int> $counters valeurs brutes des COUNTERS présents (disques ATA seulement)
     */
    public function __construct(
        public string $serial,
        public string $model,
        /** Octets */
        public int $capacity,
        /** PASSED, FAILED… (verdict global de smartctl) */
        public string $state,
        /** °C, null si le disque ne le donne pas */
        public ?int $temperature,
        public ?int $powerOnHours,
        public array $counters = [],
    ) {
    }

    /**
     * @param array<string, mixed> $record
     */
    public static function fromRecord(array $record): self
    {
        $counters = [];
        foreach ($record['attributes'] ?? [] as $attribute) {
            $name = self::COUNTERS[$attribute['id'] ?? 0] ?? null;
            if (null !== $name && isset($attribute['rv'])) {
                $counters[$name] = (int) $attribute['rv'];
            }
        }

        return new self(
            (string) ($record['serial'] ?? ''),
            (string) ($record['model'] ?? ''),
            (int) ($record['capacity'] ?? 0),
            (string) ($record['state'] ?? ''),
            isset($record['temp']) ? (int) $record['temp'] : null,
            isset($record['hours']) ? (int) $record['hours'] : null,
            $counters,
        );
    }
}
