<?php

namespace App\Tests\Card\Type;

use App\Card\Type\DaguCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Dagu\DaguClient;
use App\Source\SourceUnavailableException;
use App\Tests\Source\Dagu\DaguClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DaguCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'taches' => new ServiceDefinition('taches', 'Dagu', url: 'https://taches.example', check: 'http://10.0.0.9:8090'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans URL'),
        ], []);
    }

    private function card(?MockHttpClient $http = null): DaguCard
    {
        return new DaguCard(new DaguClient($http ?? DaguClientTest::httpClient(), 'cle'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(DaguCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('t', 'dagu', null, 2, 60, $resolver->resolve(['service' => 'taches', ...$options]), 2);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function fetch(array $options = []): array
    {
        $card = $this->card();

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'taches', 'label' => 'dashboard', 'runs' => 10, 'titles' => []], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Tâches', 'url' => 'https://taches.example'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testTasks(): void
    {
        $tasks = $this->fetch(['titles' => ['nuit' => 'Sauvegardes']])['tasks'];

        self::assertSame(['nuit', 'papiers', 'neuve'], array_column($tasks, 'name'));
        self::assertSame(
            [
                'name' => 'nuit',
                'title' => 'Sauvegardes',
                'description' => 'Sauvegardes de la nuit',
                'schedule' => 'chaque jour à 3 h 05',
                'nextRun' => '2026-11-10T03:05:00+01:00',
                'suspended' => false,
                'lastRun' => ['state' => 'running', 'startedAt' => '2026-11-09T03:05:00+01:00', 'finishedAt' => null, 'manual' => false],
                // Du plus ancien au plus récent
                'history' => [
                    ['state' => 'failed', 'startedAt' => '2026-11-07T03:05:00+01:00', 'manual' => false],
                    ['state' => 'partial', 'startedAt' => '2026-11-08T03:05:00+01:00', 'manual' => false],
                    ['state' => 'running', 'startedAt' => '2026-11-09T03:05:00+01:00', 'manual' => false],
                ],
                'url' => 'https://taches.example/dags/nuit',
            ],
            $tasks[0],
        );
        self::assertSame('Papiers', $tasks[1]['title']);
        self::assertSame('le lundi à 7 h 30', $tasks[1]['schedule']);
        self::assertNull($tasks[2]['schedule']);
        self::assertNull($tasks[2]['lastRun']);
        self::assertSame([], $tasks[2]['history']);
    }

    public function testReadsTheApiOnTheInternalAddress(): void
    {
        $urls = [];
        $card = $this->card(new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return DaguClientTest::homelab($method, $url);
        }));
        $card->fetch($this->instance($card), $this->dashboard);

        self::assertStringStartsWith('http://10.0.0.9:8090/api/v1/dags?', $urls[0]);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function schedules(): iterable
    {
        yield 'tous les jours' => ['5 3 * * *', 'chaque jour à 3 h 05'];
        yield 'heure pile' => ['0 8 * * *', 'chaque jour à 8 h'];
        yield 'un jour de la semaine' => ['30 7 * * 1', 'le lundi à 7 h 30'];
        yield 'dimanche (0 ou 7)' => ['0 4 * * 7', 'le dimanche à 4 h'];
        yield 'toutes les N minutes' => ['*/10 * * * *', 'toutes les 10 min'];
        yield 'autre : tel quel' => ['0 3 1 * *', 'cron 0 3 1 * *'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('schedules')]
    public function testScheduleInWords(string $cron, string $expected): void
    {
        self::assertSame($expected, DaguCard::schedule($cron));
    }

    public function testSourceDown(): void
    {
        $card = $this->card(new MockHttpClient(new MockResponse('', ['error' => 'refusé'])));

        $this->expectException(SourceUnavailableException::class);
        $card->fetch($this->instance($card), $this->dashboard);
    }
}
