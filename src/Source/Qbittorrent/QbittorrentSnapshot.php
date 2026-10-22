<?php

namespace App\Source\Qbittorrent;

final readonly class QbittorrentSnapshot
{
    /**
     * @param list<QbittorrentTorrent> $torrents
     */
    public function __construct(
        /** Total reçu depuis l'installation, octets */
        public int $downloaded,
        /** Total envoyé depuis l'installation, octets */
        public int $uploaded,
        /** octets/s */
        public int $downloadSpeed,
        /** octets/s */
        public int $uploadSpeed,
        public ?float $ratio,
        public array $torrents,
    ) {
    }
}
