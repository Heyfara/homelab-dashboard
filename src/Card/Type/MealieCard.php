<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Mealie\MealieClient;
use App\Source\Mealie\MealieMeal;
use App\Source\Mealie\MealieRecipe;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Recettes Mealie : l'idée recette du jour (tirée au sort, la même toute la
 * journée), le planning des repas s'il est rempli, et les recettes en slider.
 *
 *     - type: mealie
 *       service: mealie
 *       span: 2
 *       rows: 2
 *       refresh: 3600
 */
#[AsCard('mealie')]
final readonly class MealieCard implements CardTypeInterface
{
    public function __construct(
        private MealieClient $mealie,
        /** Date du jour (AAAA-MM-JJ), pour les tests ; sinon aujourd'hui à Paris */
        private ?string $today = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setDefault('limit', 12)
            ->setAllowedTypes('limit', 'int')
            ->setAllowedValues('limit', static fn (int $n): bool => $n >= 1 && $n <= 50)
            ->setDefault('plan_days', 7)
            ->setAllowedTypes('plan_days', 'int')
            ->setAllowedValues('plan_days', static fn (int $n): bool => $n >= 0 && $n <= 31)
            ->setInfo('plan_days', 'jours de planning affichés à partir d\'aujourd\'hui ; 0 = sans planning');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return ['title' => $card->title ?? $service->name, 'service' => $service->name, 'url' => $service->url, 'icon' => $service->icon];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];
        $api = $service->check ?? (string) $service->url;
        $public = rtrim((string) $service->url, '/');
        $group = $this->mealie->groupSlug($api);
        $today = new \DateTimeImmutable($this->today ?? 'today', new \DateTimeZone('Europe/Paris'));

        $recipes = $this->mealie->recipes($api);
        $view = static fn (MealieRecipe $r): array => [
            'id' => $r->id,
            'name' => $r->name,
            'image' => $r->hasImage ? \sprintf('%s/api/media/recipes/%s/images/min-original.webp', $public, $r->id) : null,
            'link' => \sprintf('%s/g/%s/r/%s', $public, $group, $r->slug),
            'totalTime' => $r->totalTime,
            'rating' => $r->rating,
        ];

        $plan = [];
        if ($card->options['plan_days'] > 0) {
            $to = $today->modify(\sprintf('+%d days', $card->options['plan_days'] - 1));
            $plan = array_map(static fn (MealieMeal $m): array => [
                'date' => $m->date,
                'type' => $m->type,
                'title' => '' !== $m->title ? $m->title : (string) $m->recipe?->name,
                'recipe' => null !== $m->recipe ? $view($m->recipe) : null,
            ], $this->mealie->mealPlan($api, $today, $to));
        }

        return [
            'today' => $today->format('Y-m-d'),
            // Tirage stable sur la journée : même recette jusqu'à minuit.
            'idea' => [] !== $recipes ? $view($recipes[crc32($today->format('Y-m-d')) % \count($recipes)]) : null,
            'plan' => $plan,
            'recipes' => array_map($view, \array_slice($recipes, 0, $card->options['limit'])),
            'total' => \count($recipes),
        ];
    }
}
