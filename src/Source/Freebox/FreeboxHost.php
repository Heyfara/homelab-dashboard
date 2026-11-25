<?php

namespace App\Source\Freebox;

/**
 * Un appareil vu par la Freebox (`lan/browser/pub`), actif ou non.
 */
final readonly class FreeboxHost
{
    public function __construct(
        /** En majuscules, `AA:BB:CC:DD:EE:FF` */
        public string $mac,
        public ?string $name,
        /** Fabricant déduit de la MAC */
        public ?string $vendor,
        /** Type deviné par la Freebox : laptop, smartphone, printer, workstation… */
        public string $type,
        /** Adresse IPv4 active, sinon la dernière connue */
        public ?string $ipv4,
        public bool $active,
        public ?\DateTimeImmutable $lastActivity,
        /** Première apparition sur le réseau */
        public ?\DateTimeImmutable $firstActivity = null,
    ) {
    }
}
