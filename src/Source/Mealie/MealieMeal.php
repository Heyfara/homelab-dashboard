<?php

namespace App\Source\Mealie;

/**
 * Une entrée du planning des repas : une recette, ou un simple titre (« Restes »).
 */
final readonly class MealieMeal
{
    public function __construct(
        /** AAAA-MM-JJ */
        public string $date,
        /** breakfast, lunch, dinner, side, snack, dessert, drink */
        public string $type,
        public string $title,
        public ?MealieRecipe $recipe,
    ) {
    }
}
