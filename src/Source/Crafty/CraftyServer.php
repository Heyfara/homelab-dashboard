<?php

namespace App\Source\Crafty;

final readonly class CraftyServer
{
    /**
     * @param list<string> $players pseudos connectés (échantillon renvoyé par le serveur)
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $port,
        /** online, starting, stopped ou crashed */
        public string $state,
        public int $online,
        public int $max,
        public array $players,
        public ?string $version,
        /** Description (MOTD), sans les codes couleur Minecraft */
        public ?string $motd,
        /** Icône du serveur en data URI, si le serveur en a une */
        public ?string $icon,
        /** Taille du monde telle que l'affiche Crafty : « 423.2MB » */
        public ?string $worldSize,
        public ?\DateTimeImmutable $startedAt,
    ) {
    }
}
