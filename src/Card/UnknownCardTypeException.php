<?php

namespace App\Card;

final class UnknownCardTypeException extends \InvalidArgumentException
{
    /**
     * @param list<string> $known
     */
    public function __construct(string $type, array $known)
    {
        parent::__construct(\sprintf('Type de carte inconnu « %s » (types connus : %s)', $type, implode(', ', $known) ?: 'aucun'));
    }
}
