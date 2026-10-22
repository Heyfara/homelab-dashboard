<?php

namespace App\Source\Beszel;

/**
 * Une fiche de la collection « containers » de Beszel. L'agent n'y met que les
 * containers qui tournent, et jamais leurs variables d'environnement.
 */
final readonly class BeszelContainer
{
    public function __construct(
        public string $id,
        public string $name,
        /** Texte de Docker : « Up 7 days », « Restarting (1) 4 seconds ago »… */
        public string $status,
        /** 0 sans healthcheck, 1 démarre, 2 sain, 3 en difficulté */
        public int $health,
        /** % d'un cœur */
        public float $cpu,
        /** Mo */
        public float $memory,
        /** Dernière mise à jour par l'agent, timestamp Unix */
        public int $updated,
    ) {
    }
}
