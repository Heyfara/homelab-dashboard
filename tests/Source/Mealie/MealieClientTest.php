<?php

namespace App\Tests\Source\Mealie;

use App\Source\Mealie\MealieClient;
use App\Source\Mealie\MealieRecipe;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MealieClientTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $requests = [];

    private function client(callable $respond, string $token = 'jeton'): MealieClient
    {
        return new MealieClient(new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$url, $options];

            return $respond((string) parse_url($url, \PHP_URL_PATH));
        }), $token);
    }

    private static function fixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/'.$name));
    }

    public function testRecipesNewestFirst(): void
    {
        $recipes = $this->client(static fn () => self::fixture('mealie-recipes.json'))->recipes('http://mealie:9000');

        self::assertEquals(new MealieRecipe('r1', 'crepes-de-froment', 'Crêpes de froment', true, '1 h 45 min', 5.0), $recipes[0]);
        self::assertFalse($recipes[2]->hasImage);

        [$url, $options] = $this->requests[0];
        self::assertStringStartsWith('http://mealie:9000/api/recipes?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame(['created_at', 'desc', '200'], [$query['orderBy'], $query['orderDirection'], $query['perPage']]);
        self::assertContains('Authorization: Bearer jeton', $options['headers']);
    }

    public function testMealPlanBetweenTwoDates(): void
    {
        $meals = $this->client(static fn () => self::fixture('mealie-mealplans.json'))->mealPlan('http://mealie:9000', new \DateTimeImmutable('2026-10-20'), new \DateTimeImmutable('2026-10-26'));

        self::assertSame(['2026-10-20', 'dinner', 'Gratin de blettes'], [$meals[0]->date, $meals[0]->type, $meals[0]->recipe?->name]);
        self::assertSame(['lunch', 'Restes', null], [$meals[1]->type, $meals[1]->title, $meals[1]->recipe]);

        parse_str((string) parse_url($this->requests[0][0], \PHP_URL_QUERY), $query);
        self::assertSame(['2026-10-20', '2026-10-26'], [$query['start_date'], $query['end_date']]);
        self::assertStringContainsString('/api/households/mealplans', $this->requests[0][0]);
    }

    public function testGroupSlug(): void
    {
        $client = $this->client(static fn () => new JsonMockResponse(['username' => 'x', 'groupSlug' => 'home']));

        self::assertSame('home', $client->groupSlug('http://mealie:9000'));
    }

    public function testRejectedToken(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Jeton Mealie refusé (401), vérifier MEALIE_API_TOKEN');

        $this->client(static fn () => new MockResponse('', ['http_code' => 401]))->recipes('http://mealie:9000');
    }

    public function testMissingToken(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Jeton Mealie absent (MEALIE_API_TOKEN)');

        $this->client(static fn () => throw new \LogicException('aucun appel attendu'), '')->recipes('http://mealie:9000');
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Mealie injoignable/');

        $this->client(static fn () => new MockResponse('', ['error' => 'Connection refused']))->recipes('http://mealie:9000');
    }
}
