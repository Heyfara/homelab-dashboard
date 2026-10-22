<?php

namespace App\Source\Container;

use App\Dashboard\Model\Host;
use App\Source\SourceUnavailableException;

/**
 * D'où vient l'état des containers. Les cartes ne connaissent que cette
 * interface : changer de source (Beszel aujourd'hui) ne les touche pas.
 */
interface ContainerStatusSource
{
    /**
     * @throws SourceUnavailableException la source ne sait pas répondre pour cette machine
     */
    public function status(Host $host, string $container): ContainerState;

    /**
     * Tous les containers connus de la machine, par nom.
     *
     * @return array<string, ContainerState>
     *
     * @throws SourceUnavailableException
     */
    public function all(Host $host): array;
}
