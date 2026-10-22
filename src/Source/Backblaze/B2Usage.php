<?php

namespace App\Source\Backblaze;

final readonly class B2Usage
{
    public function __construct(
        /** Octets stockés, toutes versions comprises (c'est ce qui est facturé) */
        public int $bytes,
        public int $files,
        public ?\DateTimeImmutable $lastUpload,
    ) {
    }
}
