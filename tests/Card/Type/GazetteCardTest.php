<?php

namespace App\Tests\Card\Type;

use App\Card\Type\GazetteCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Gazette\GazetteClient;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class GazetteCardTest extends TestCase
{
    private Dashboard $dashboard;

    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'gazette' => new ServiceDefinition('gazette', 'Gazette', url: 'https://gazette.example', icon: 'https://gazette.example/build/favicon.svg'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    private function card(string $today = '2026-11-22', ?MockResponse $response = null): GazetteCard
    {
        $response ??= new MockResponse((string) file_get_contents(__DIR__.'/../../fixtures/gazette-issues.json'));

        return new GazetteCard(new GazetteClient(new MockHttpClient(function (string $method, string $url) use ($response) {
            $this->urls[] = $url;

            return $response;
        })), $today);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(GazetteCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('g', 'gazette', null, 1, 3600, $resolver->resolve(['service' => 'gazette', ...$options]), 2);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'gazette', 'limit' => 3, 'warn_after_days' => 35, 'show_draft' => true], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testLimitMustBePositive(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['limit' => 0]);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(
            ['title' => 'Gazette', 'service' => 'Gazette', 'url' => 'https://gazette.example', 'icon' => 'https://gazette.example/build/favicon.svg'],
            $card->describe($this->instance($card), $this->dashboard),
        );
    }

    public function testLastSentIssuesAndDraft(): void
    {
        $card = $this->card();
        $data = $card->fetch($this->instance($card, ['limit' => 2]), $this->dashboard);

        self::assertSame(['https://gazette.example/api/issues'], $this->urls);
        // Envoyée le 30 octobre au soir (heure de Paris), on est le 22 novembre
        self::assertSame(23, $data['daysSince']);
        self::assertFalse($data['late']);
        self::assertSame(35, $data['warnAfterDays']);
        self::assertSame([
            ['number' => 3, 'title' => 'La rentrée', 'month' => '2026-10', 'sentAt' => '2026-10-30T19:12:00+00:00', 'link' => 'https://gazette.example/numeros/b2c3d4e5'],
            ['number' => 2, 'title' => '', 'month' => '2026-09', 'sentAt' => '2026-09-28T17:40:00+00:00', 'link' => 'https://gazette.example/numeros/a1b2c3d4'],
        ], $data['sent']);
        self::assertSame(['number' => 4, 'title' => '', 'month' => '2026-11', 'status' => 'brouillon', 'notes' => 5, 'link' => 'https://gazette.example/numeros/c3d4e5f6'], $data['draft']);
    }

    public function testLateAfterWarnDays(): void
    {
        $card = $this->card('2026-12-05');
        $data = $card->fetch($this->instance($card), $this->dashboard);

        self::assertSame(36, $data['daysSince']);
        self::assertTrue($data['late']);
    }

    public function testDaysCountedInParisCalendar(): void
    {
        // 31 août 21 h 30 UTC = 31 août 23 h 30 à Paris : le lendemain fait J+1
        $issue = ['id' => 'f0e1d2c3', 'number' => 1, 'title' => '', 'month' => '2026-08', 'status' => 'envoye', 'notes' => 0, 'sentAt' => '2026-08-31T21:30:00+00:00'];
        $card = $this->card('2026-09-01', new JsonMockResponse([$issue]));

        self::assertSame(1, $card->fetch($this->instance($card), $this->dashboard)['daysSince']);
    }

    public function testNeverSent(): void
    {
        $issue = ['id' => 'edd83558', 'number' => 1, 'title' => '', 'month' => '2026-11', 'status' => 'teste', 'notes' => 4, 'sentAt' => null];
        $card = $this->card(response: new JsonMockResponse([$issue]));
        $data = $card->fetch($this->instance($card), $this->dashboard);

        self::assertNull($data['daysSince']);
        self::assertFalse($data['late']);
        self::assertSame([], $data['sent']);
        self::assertSame('teste', $data['draft']['status']);
    }

    public function testWithoutDraft(): void
    {
        $card = $this->card();

        self::assertNull($card->fetch($this->instance($card, ['show_draft' => false]), $this->dashboard)['draft']);
    }

    public function testNoIssueAtAll(): void
    {
        $card = $this->card(response: new JsonMockResponse([]));
        $data = $card->fetch($this->instance($card), $this->dashboard);

        self::assertSame([null, [], null], [$data['daysSince'], $data['sent'], $data['draft']]);
    }

    public function testSourceDown(): void
    {
        $card = $this->card(response: new MockResponse('', ['http_code' => 502]));

        $this->expectException(SourceUnavailableException::class);
        $card->fetch($this->instance($card), $this->dashboard);
    }
}
