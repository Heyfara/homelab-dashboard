<?php

namespace App\Tests\Card\Type;

use App\Card\CardActionRefusedException;
use App\Card\Type\UrssafCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Dagu\DaguClient;
use App\Source\Dagu\DaguRunner;
use App\Tests\Source\Dagu\DaguClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class UrssafCardTest extends TestCase
{
    private Dashboard $dashboard;

    /** @var list<array{string, string, array<string, mixed>}> requêtes du lanceur */
    private array $launches = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'taches' => new ServiceDefinition('taches', 'Dagu', url: 'https://taches.example', check: 'http://10.0.0.9:8090'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans URL'),
        ], []);
    }

    private function card(?MockResponse $launch = null): UrssafCard
    {
        $runner = new DaguRunner(new MockHttpClient(function (string $method, string $url, array $options) use ($launch): MockResponse {
            $this->launches[] = [$method, $url, $options];

            return $launch ?? new JsonMockResponse(['dagRunId' => 'run-3']);
        }), 'cle-operateur');

        return new UrssafCard(new DaguClient(DaguClientTest::httpClient(), 'cle'), $runner);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(UrssafCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('u', 'urssaf', null, 2, 5, $resolver->resolve(['service' => 'taches', 'task' => 'declaration', 'screen' => 'https://ecran.example', ...$options]), 3);
    }

    public function testOptions(): void
    {
        self::assertEquals(
            ['service' => 'taches', 'task' => 'declaration', 'screen' => 'https://ecran.example', 'test' => false, 'deadlines' => []],
            $this->instance($this->card())->options,
        );

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['deadlines' => [['period' => 'T4', 'opens' => '2027-01-01', 'due' => 'pas une date']]]);
    }

    public function testDeadlinesWrittenAsYamlDates(): void
    {
        // Le YAML lit `opens: 2027-01-01` (sans guillemets) comme un horodatage.
        $deadlines = [['period' => 'T4', 'opens' => 1798761600, 'due' => new \DateTimeImmutable('2027-01-31')]];

        self::assertSame([['period' => 'T4', 'opens' => '2027-01-01', 'due' => '2027-01-31']], $this->instance($this->card(), ['deadlines' => $deadlines])->options['deadlines']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(
            ['title' => 'Déclaration Urssaf', 'taskUrl' => 'https://taches.example/dags/declaration', 'test' => false],
            $card->describe($this->instance($card), $this->dashboard),
        );
    }

    public function testRunningSessionWithItsScreenAndStep(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card), $this->dashboard);

        self::assertSame([
            'id' => 'run-2',
            'state' => 'running',
            'startedAt' => '2026-11-16T18:00:00+01:00',
            'finishedAt' => null,
            'amount' => 120,
            'test' => false,
            'step' => 'rempli',
            'message' => 'Montant 120 € saisi.',
            'screenUrl' => 'https://ecran.example/vnc.html?autoconnect=1&resize=scale&reconnect=1&password=ab12cd34',
        ], $data['run']);
    }

    public function testNextDeadline(): void
    {
        $deadlines = [
            ['period' => '3e trimestre 2026', 'opens' => '2026-10-01', 'due' => '2026-11-02'],
            ['period' => '4e trimestre 2026', 'opens' => '2027-01-01', 'due' => '2027-01-31'],
            ['period' => '1er trimestre 2027', 'opens' => '2027-04-01', 'due' => '2027-04-30'],
        ];

        self::assertSame(['period' => '4e trimestre 2026', 'opens' => '2027-01-01', 'due' => '2027-01-31', 'open' => false], UrssafCard::nextDeadline($deadlines, new \DateTimeImmutable('2026-11-16')));
        self::assertSame(['period' => '4e trimestre 2026', 'opens' => '2027-01-01', 'due' => '2027-01-31', 'open' => true], UrssafCard::nextDeadline($deadlines, new \DateTimeImmutable('2027-01-31')));
        self::assertNull(UrssafCard::nextDeadline($deadlines, new \DateTimeImmutable('2027-05-01')));
    }

    public function testStartSendsAmountTestFlagAndAFreshScreenPassword(): void
    {
        $card = $this->card();
        $result = $card->runAction('start', ['amount' => 250], $this->instance($card, ['test' => true]), $this->dashboard);

        self::assertSame('run-3', $result['runId']);
        [$method, $url, $options] = $this->launches[0];
        self::assertSame(['POST', 'http://10.0.0.9:8090/api/v1/dags/declaration/start'], [$method, $url]);
        $params = json_decode(json_decode($options['body'], true)['params'], true);
        self::assertSame('250', $params['MONTANT']);
        self::assertSame('true', $params['TEST']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $params['VNC_PASSWORD']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'négatif' => [-1];
        yield 'texte' => ['12'];
        yield 'décimal' => [12.5];
        yield 'absent' => [null];
        yield 'trop grand' => [100_000_000];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAmounts')]
    public function testStartRefusesInvalidAmounts(mixed $amount): void
    {
        $card = $this->card();

        $this->expectExceptionObject(new CardActionRefusedException('Montant attendu : un nombre entier d\'euros, 0 ou plus.'));
        $card->runAction('start', ['amount' => $amount], $this->instance($card), $this->dashboard);
    }

    public function testStopTheRunningSession(): void
    {
        $card = $this->card(new MockResponse('', ['http_code' => 200]));
        $card->runAction('stop', [], $this->instance($card), $this->dashboard);

        self::assertSame(['POST', 'http://10.0.0.9:8090/api/v1/dag-runs/declaration/run-2/stop'], \array_slice($this->launches[0], 0, 2));
    }

    public function testStopWithoutRunningSession(): void
    {
        $card = $this->card();

        $this->expectExceptionObject(new CardActionRefusedException('Aucune session en cours.'));
        $card->runAction('stop', [], $this->instance($card, ['task' => 'neuve']), $this->dashboard);
    }
}
