<?php

namespace App\Tests\Card\Type;

use App\Card\Type\MealieCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Mealie\MealieClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MealieCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'mealie' => new ServiceDefinition('mealie', 'Mealie', url: 'https://mealie.example', check: 'http://mealie:9000', icon: 'sh-mealie'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    private function card(string $today = '2026-10-20', bool $emptyPlan = false): MealieCard
    {
        $http = new MockHttpClient(static function (string $method, string $url) use ($emptyPlan) {
            $path = (string) parse_url($url, \PHP_URL_PATH);

            return match ($path) {
                '/api/users/self' => new JsonMockResponse(['groupSlug' => 'home']),
                '/api/recipes' => new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/mealie-recipes.json')),
                '/api/households/mealplans' => $emptyPlan ? new JsonMockResponse(['items' => []]) : new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/mealie-mealplans.json')),
            };
        });

        return new MealieCard(new MealieClient($http, 'jeton'), $today);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(MealieCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('m', 'mealie', null, 2, 3600, $resolver->resolve(['service' => 'mealie', ...$options]), 2);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'mealie', 'limit' => 12, 'plan_days' => 7], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Mealie', 'service' => 'Mealie', 'url' => 'https://mealie.example', 'icon' => 'sh-mealie'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testRecipesWithPublicPhotosAndLinks(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card), $this->dashboard);

        self::assertSame(3, $data['total']);
        self::assertSame([
            'id' => 'r1',
            'name' => 'Crêpes de froment',
            'image' => 'https://mealie.example/api/media/recipes/r1/images/min-original.webp',
            'link' => 'https://mealie.example/g/home/r/crepes-de-froment',
            'totalTime' => '1 h 45 min',
            'rating' => 5.0,
        ], $data['recipes'][0]);
        self::assertNull($data['recipes'][2]['image']);
    }

    public function testRecipeOfTheDayIsStableForADayAndChangesNextDay(): void
    {
        $card = $this->card('2026-10-20');
        $first = $card->fetch($this->instance($card), $this->dashboard)['idea'];
        self::assertSame($first, $card->fetch($this->instance($card), $this->dashboard)['idea']);

        $ideas = [];
        foreach (['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24'] as $day) {
            $other = $this->card($day);
            $ideas[] = $other->fetch($this->instance($other), $this->dashboard)['idea']['id'];
        }
        self::assertGreaterThan(1, \count(array_unique([$first['id'], ...$ideas])), 'l\'idée change d\'un jour à l\'autre');
    }

    public function testMealPlan(): void
    {
        $card = $this->card();
        $plan = $card->fetch($this->instance($card), $this->dashboard)['plan'];

        self::assertSame('2026-10-20', $plan[0]['date']);
        self::assertSame('dinner', $plan[0]['type']);
        self::assertSame('Gratin de blettes', $plan[0]['title']);
        self::assertSame('https://mealie.example/g/home/r/gratin-de-blettes', $plan[0]['recipe']['link']);
        self::assertSame(['lunch', 'Restes', null], [$plan[1]['type'], $plan[1]['title'], $plan[1]['recipe']]);
    }

    public function testNoPlanWhenDisabled(): void
    {
        $card = $this->card();

        self::assertSame([], $card->fetch($this->instance($card, ['plan_days' => 0]), $this->dashboard)['plan']);
    }

    public function testLimit(): void
    {
        $card = $this->card();

        self::assertCount(2, $card->fetch($this->instance($card, ['limit' => 2]), $this->dashboard)['recipes']);
    }
}
