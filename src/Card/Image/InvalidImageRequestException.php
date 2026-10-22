<?php

namespace App\Card\Image;

/**
 * Demande d'image refusée : signature absente ou fausse, ou carte sans images.
 */
final class InvalidImageRequestException extends \RuntimeException
{
}
