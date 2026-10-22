<?php

namespace App\Source\Qbittorrent;

final readonly class QbittorrentTorrent
{
    public function __construct(
        public string $hash,
        public string $name,
        /** octets */
        public int $size,
        /** 0 à 1 */
        public float $progress,
        /** État brut de qBittorrent : downloading, stalledUP, stoppedDL… */
        public string $state,
        /** timestamp Unix */
        public int $addedOn,
        /** timestamp Unix, null tant que le téléchargement n'est pas fini */
        public ?int $completedOn,
    ) {
    }
}
