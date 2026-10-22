<?php

namespace App\Card;

/**
 * Déclare un type de carte et son identifiant, celui qu'on écrit dans
 * `type:` du YAML et sous lequel le composant React est enregistré.
 *
 *     #[AsCard('machine')]
 *     final class MachineCard implements CardTypeInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsCard
{
    public const TAG = 'app.card_type';

    public function __construct(
        public string $type,
    ) {
    }
}
