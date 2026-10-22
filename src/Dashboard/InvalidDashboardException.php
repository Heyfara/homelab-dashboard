<?php

namespace App\Dashboard;

/**
 * Configuration du dashboard invalide. Porte toutes les erreurs trouvées, pas
 * seulement la première, pour qu'un lint les affiche d'un coup.
 */
final class InvalidDashboardException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public readonly array $errors,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("Configuration du dashboard invalide :\n- ".implode("\n- ", $errors), 0, $previous);
    }
}
