<?php

namespace App\Dashboard\Model;

final readonly class Settings
{
    public function __construct(
        public int $columns,
        public int $refresh,
    ) {
    }
}
