<?php

namespace App\Source\OpenWrt;

use App\Source\SourceUnavailableException;

/**
 * Relevé d'un point d'accès OpenWrt, tel que l'écrit le script dashboard-probe
 * (rôle Ansible openwrt-dashboard-probe).
 */
final readonly class OpenWrtSnapshot
{
    /**
     * @param float                                                       $readAt   instant du relevé côté dashboard (s, à la microseconde)
     * @param array<string, array{int, int}>                              $ports    octets [reçus, émis] par port du bridge
     * @param array<string, float>                                        $radios   fréquence de chaque radio (GHz)
     * @param list<array{mac: string, port: string, age: float}>          $fdb      MAC apprises par le bridge, âge en s
     * @param list<array{mac: string, radio: string, signal: int, inactive: int}> $stations appareils associés, inactivité en ms
     */
    public function __construct(
        public float $readAt,
        public array $ports,
        public array $radios,
        public array $fdb,
        public array $stations,
    ) {
    }

    public static function fromJson(string $json, float $readAt): self
    {
        $data = json_decode($json, true);
        if (!\is_array($data) || !isset($data['ports'], $data['radios'], $data['fdb'], $data['stations'])) {
            throw new SourceUnavailableException(\sprintf('Relevé illisible : %s', mb_substr(trim($json), 0, 120)));
        }

        return self::fromArray($data, $readAt);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, float $readAt): self
    {
        $ports = [];
        foreach ($data['ports'] as $name => [$rx, $tx]) {
            $ports[(string) $name] = [(int) $rx, (int) $tx];
        }

        return new self(
            $readAt,
            $ports,
            array_map(floatval(...), $data['radios']),
            array_map(static fn (array $f): array => ['mac' => strtoupper($f[0]), 'port' => (string) $f[1], 'age' => (float) $f[2]], $data['fdb']),
            array_map(static fn (array $s): array => ['mac' => strtoupper($s[0]), 'radio' => (string) $s[1], 'signal' => (int) $s[2], 'inactive' => (int) $s[3]], $data['stations']),
        );
    }
}
