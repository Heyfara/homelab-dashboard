<?php

namespace App\Network;

use App\Source\OpenWrt\OpenWrtSnapshot;

/**
 * Position de chaque appareil, déduite de ce que voient les points d'accès :
 *
 *  1. associé en Wi-Fi, ou vu sur un port lanX d'un point d'accès : il y est ;
 *  2. vu ainsi il y a moins de `memory` secondes : on garde ce point d'accès
 *     (« vu il y a… »), pour qu'une connexion faible ne saute pas de place en place ;
 *  3. jamais vu sur un point d'accès (depuis `keep` secondes) mais présent en
 *     amont, ou point d'accès lui-même : filaire sur le switch de la baie ;
 *  4. sinon : position incertaine (null).
 *
 * La mémoire (dernier point d'accès de chaque MAC) est un tableau simple, gardé
 * par l'appelant entre deux relevés.
 */
final readonly class Placement
{
    /** Au-delà, une entrée du bridge n'est plus une présence : le bridge garde les MAC 5 min. */
    private const FDB_FRESH = 60.0;

    public function __construct(
        private string $wiredZone,
        private int $memory,
        private int $keep,
    ) {
    }

    /**
     * @param list<string>                    $macs           appareils à placer (MAJUSCULES)
     * @param array<string, AccessPoint>      $aps
     * @param array<string, ?OpenWrtSnapshot> $snapshots      par point d'accès, null s'il n'a pas répondu
     * @param list<string>                    $infrastructure MAC des points d'accès eux-mêmes
     * @param array<string, mixed>            $memory
     *
     * @return array{array<string, ?array{zone: ?string, ap: ?string, link: ?string, port: ?string, band: ?string, signal: ?int, seenAt: ?int}>, array<string, mixed>}
     */
    public function place(array $macs, array $aps, array $snapshots, array $infrastructure, array $memory, int $now): array
    {
        $seen = [];
        $upstream = [];
        foreach ($snapshots as $id => $s) {
            if (null === $s || !isset($aps[$id])) {
                continue;
            }
            foreach ($s->fdb as $f) {
                if ($f['age'] > self::FDB_FRESH) {
                    continue;
                }
                if ($f['port'] === $aps[$id]->uplink) {
                    $upstream[$f['mac']] = true;
                    continue;
                }
                $wifi = isset($s->radios[$f['port']]);
                self::keepFreshest($seen, $f['mac'], [
                    'ap' => $id, 'link' => $wifi ? 'wifi' : 'cable', 'port' => $f['port'],
                    'band' => $wifi ? self::band($s->radios[$f['port']]) : null, 'signal' => null,
                    'at' => $now - (int) round($f['age']),
                ]);
            }
            foreach ($s->stations as $st) {
                self::keepFreshest($seen, $st['mac'], [
                    'ap' => $id, 'link' => 'wifi', 'port' => $st['radio'],
                    'band' => isset($s->radios[$st['radio']]) ? self::band($s->radios[$st['radio']]) : null,
                    'signal' => $st['signal'], 'at' => $now - intdiv($st['inactive'], 1000),
                ], preferSignal: true);
            }
        }

        $memory = array_filter($memory, fn ($m): bool => \is_array($m) && ($m['at'] ?? 0) >= $now - $this->keep && isset($aps[$m['ap'] ?? '']));
        $places = [];
        foreach ($macs as $mac) {
            if (isset($seen[$mac])) {
                $memory[$mac] = $seen[$mac];
                $places[$mac] = $this->toPlace($aps, $seen[$mac], null);
            } elseif (isset($memory[$mac]) && $memory[$mac]['at'] >= $now - $this->memory) {
                $places[$mac] = $this->toPlace($aps, $memory[$mac], $memory[$mac]['at']);
            } elseif (\in_array($mac, $infrastructure, true) || (isset($upstream[$mac]) && !isset($memory[$mac]))) {
                $places[$mac] = ['zone' => $this->wiredZone, 'ap' => null, 'link' => 'cable', 'port' => null, 'band' => null, 'signal' => null, 'seenAt' => null];
            } else {
                $places[$mac] = null;
            }
        }

        return [$places, $memory];
    }

    /**
     * @param array<string, array<string, mixed>> $seen
     * @param array<string, mixed>                $entry
     */
    private static function keepFreshest(array &$seen, string $mac, array $entry, bool $preferSignal = false): void
    {
        $current = $seen[$mac] ?? null;
        // À fraîcheur égale, l'association Wi-Fi l'emporte sur le bridge : elle porte le signal.
        if (null === $current || $entry['at'] > $current['at'] || ($preferSignal && $entry['at'] === $current['at'] && $entry['ap'] === $current['ap'])) {
            $seen[$mac] = $entry;
        }
    }

    /**
     * Bande Wi-Fi d'une fréquence : « 2.4 », « 5 » ou « 6 » (GHz).
     */
    public static function band(float $ghz): string
    {
        return match (true) {
            $ghz < 3 => '2.4',
            $ghz < 5.9 => '5',
            default => '6',
        };
    }

    /**
     * @param array<string, AccessPoint> $aps
     * @param array<string, mixed>       $m
     *
     * @return array{zone: ?string, ap: ?string, link: ?string, port: ?string, band: ?string, signal: ?int, seenAt: ?int}
     */
    private function toPlace(array $aps, array $m, ?int $seenAt): array
    {
        return ['zone' => $aps[$m['ap']]->zone, 'ap' => $m['ap'], 'link' => $m['link'], 'port' => $m['port'], 'band' => $m['band'], 'signal' => $m['signal'], 'seenAt' => $seenAt];
    }
}
