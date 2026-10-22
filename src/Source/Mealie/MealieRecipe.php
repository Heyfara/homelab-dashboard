<?php

namespace App\Source\Mealie;

final readonly class MealieRecipe
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public bool $hasImage,
        /** Texte libre saisi dans Mealie : « 1 h 45 min », « 30 minutes » */
        public ?string $totalTime = null,
        /** sur 5 */
        public ?float $rating = null,
    ) {
    }
}
