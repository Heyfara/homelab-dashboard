<?php

namespace App\Source\Backblaze;

final readonly class B2Bucket
{
    public function __construct(
        public string $id,
        public string $name,
        /** allPrivate, allPublic… */
        public string $type,
    ) {
    }
}
