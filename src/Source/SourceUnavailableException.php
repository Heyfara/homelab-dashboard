<?php

namespace App\Source;

/**
 * Une source de données (Beszel, Docker, API d'un service) ne répond pas ou
 * répond de travers. Le message est affiché tel quel sur la carte.
 */
final class SourceUnavailableException extends \RuntimeException
{
}
