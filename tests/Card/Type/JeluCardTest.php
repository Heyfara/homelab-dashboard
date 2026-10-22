<?php

namespace App\Tests\Card\Type;

use App\Card\Type\JeluCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Jelu\JeluClient;
use App\Tests\Source\Jelu\JeluClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class JeluCardTest extends TestCase
{
    private Dashboard $dashboard;
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'lectures' => new ServiceDefinition('lectures', 'Jelu', url: 'https://lectures.example', check: 'http://jelu:11111', icon: 'sh-jelu'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    private function card(): JeluCard
    {
        return new JeluCard(new JeluClient(new MockHttpClient(function (string $method, string $url) {
            $this->urls[] = $url;
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return JeluClientTest::homelab((string) parse_url($url, \PHP_URL_PATH), $query);
        }), 'jeton'), '2026-10-20');
    }

    private function instance(JeluCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('j', 'jelu', null, 2, 900, $resolver->resolve(['service' => 'lectures', ...$options]), 2);
    }

    private function fetch(array $options = []): array
    {
        $card = $this->card();

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'lectures', 'readers' => [], 'limit' => 12], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Jelu', 'service' => 'Jelu', 'url' => 'https://lectures.example', 'icon' => 'sh-jelu'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testReadingNowWithReaderNamesAndPublicCovers(): void
    {
        $data = $this->fetch(['readers' => ['alice' => 'Alice', 'bob' => 'Bob']]);

        self::assertSame([
            'reader' => 'Alice',
            'bookId' => 'b1',
            'title' => 'Le Phare des brumes',
            'authors' => 'Jeanne Fictive',
            'cover' => 'https://lectures.example/files/le-phare.jpg',
            'link' => 'https://lectures.example/books/b1',
            'percent' => 40,
            'date' => '2026-10-10T20:00:00+00:00',
        ], $data['reading'][0]);
        self::assertNull($data['reading'][1]['cover']);
    }

    public function testFinishedThisYearCountedPerReader(): void
    {
        $data = $this->fetch(['readers' => ['alice' => 'Alice', 'bob' => 'Bob']]);

        self::assertSame(2026, $data['year']);
        self::assertSame([['name' => 'Alice', 'finished' => 2], ['name' => 'Bob', 'finished' => 1]], $data['readers']);
        self::assertSame(['Le Dernier Tome', 'Contes du soir', 'Voyage au centre'], array_column($data['finished'], 'title'));
        self::assertStringContainsString('endedAfter=2026-01-01', $this->urls[1]);
    }

    public function testOnlyChosenReaders(): void
    {
        $data = $this->fetch(['readers' => ['bob' => 'Bob']]);

        self::assertSame(['Bob'], array_unique(array_column([...$data['reading'], ...$data['finished']], 'reader')));
        self::assertSame([['name' => 'Bob', 'finished' => 1]], $data['readers']);
    }

    public function testReaderLoginsIgnoreCase(): void
    {
        $data = $this->fetch(['readers' => ['ALICE' => 'Alice']]);

        self::assertSame([['name' => 'Alice', 'finished' => 2]], $data['readers']);
        self::assertSame('Alice', $data['reading'][0]['reader']);
    }

    public function testWithoutReadersEveryoneByLogin(): void
    {
        $data = $this->fetch();

        self::assertSame([['name' => 'Alice', 'finished' => 2], ['name' => 'Bob', 'finished' => 1]], $data['readers'], 'nom de connexion, initiale en majuscule');
    }

    public function testListedReaderWithNothingFinishedStillCounted(): void
    {
        self::assertSame(['name' => 'Chloé', 'finished' => 0], $this->fetch(['readers' => ['alice' => 'Alice', 'chloe' => 'Chloé']])['readers'][1]);
    }
}
