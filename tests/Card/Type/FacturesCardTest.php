<?php

namespace App\Tests\Card\Type;

use App\Card\Type\FacturesCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Paperless\PaperlessClient;
use App\Source\SourceUnavailableException;
use App\Tests\Source\Paperless\PaperlessClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FacturesCardTest extends TestCase
{
    private Dashboard $dashboard;

    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'papiers' => new ServiceDefinition('papiers', 'Papiers', url: 'https://papiers.example', check: 'http://10.0.0.14:8000', icon: 'sh-paperless-ngx'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    private function card(?callable $respond = null): FacturesCard
    {
        $respond ??= static fn (string $path, array $query) => PaperlessClientTest::homelab($path, $query);

        return new FacturesCard(new PaperlessClient(new MockHttpClient(function (string $method, string $url) use ($respond) {
            $this->urls[] = $url;
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return $respond((string) parse_url($url, \PHP_URL_PATH), $query);
        }), 'jeton'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(FacturesCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('f', 'factures', null, 2, 3600, $resolver->resolve(['service' => 'papiers', ...$options]), 2);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function fetch(array $options = [], ?callable $respond = null): array
    {
        $card = $this->card($respond);

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptions(): void
    {
        self::assertEquals([
            'service' => 'papiers',
            'document_type' => 'Facture',
            'soon_days' => 7,
            'fields' => ['amount' => 'Montant', 'due' => 'Échéance', 'paid' => 'Payée le'],
        ], $this->instance($this->card())->options);

        $custom = $this->instance($this->card(), ['fields' => ['due' => 'Date limite']])->options['fields'];
        self::assertSame(['amount' => 'Montant', 'due' => 'Date limite', 'paid' => 'Payée le'], $custom);
    }

    public function testServiceWithoutUrlIsRefused(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testUnknownFieldKeyIsRefused(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['fields' => ['montant' => 'Montant']]);
    }

    public function testNegativeSoonDaysIsRefused(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['soon_days' => -1]);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(
            ['title' => 'Factures à payer', 'url' => 'https://papiers.example', 'soonDays' => 7],
            $card->describe($this->instance($card), $this->dashboard),
        );
        self::assertSame([], $this->urls, 'describe ne lit pas la source');
    }

    public function testUnpaidInvoicesSortedByDueDate(): void
    {
        $data = $this->fetch();

        self::assertSame([
            'id' => 42,
            'title' => 'Facture internet',
            'correspondent' => 'Opérateur fictif',
            'amount' => 39.99,
            'currency' => 'EUR',
            'dueDate' => '2026-11-10',
            'created' => '2026-11-02',
            'url' => 'https://papiers.example/documents/42/details',
        ], $data['invoices'][0]);
        self::assertSame([42, 41, 43], array_column($data['invoices'], 'id'), 'sans échéance à la fin');
        self::assertSame(246.5, $data['invoices'][1]['amount']);
        self::assertSame('Fournisseur d\'énergie fictif', $data['invoices'][1]['correspondent']);
        self::assertSame(['correspondent' => null, 'amount' => null, 'dueDate' => null], array_intersect_key($data['invoices'][2], ['correspondent' => 1, 'amount' => 1, 'dueDate' => 1]));

        self::assertSame(['total' => 286.49, 'currency' => 'EUR', 'count' => 3, 'missingAmounts' => 1], array_diff_key($data, ['invoices' => 1]));
        foreach ($this->urls as $url) {
            self::assertStringStartsWith('http://10.0.0.14:8000/api/', $url, 'l\'API passe par l\'adresse check');
        }
    }

    public function testCorrespondentsAreNotReadWhenNoInvoiceHasOne(): void
    {
        $this->fetch([], static fn (string $path, array $query) => match ($path) {
            '/api/documents/' => new JsonMockResponse(['next' => null, 'results' => [['id' => 1, 'title' => 'Facture', 'correspondent' => null, 'custom_fields' => []]]]),
            '/api/correspondents/' => throw new \LogicException('lecture inutile'),
            default => PaperlessClientTest::homelab($path, $query),
        });

        self::assertCount(2, $this->urls);
    }

    public function testNoInvoice(): void
    {
        $data = $this->fetch(['document_type' => 'Type inexistant']);

        self::assertSame(['invoices' => [], 'total' => 0.0, 'currency' => 'EUR', 'count' => 0, 'missingAmounts' => 0], $data);
    }

    public function testMissingPaidFieldListsEveryInvoiceOfTheType(): void
    {
        $query = null;
        $this->fetch(['fields' => ['paid' => 'Champ absent']], static function (string $path, array $q) use (&$query) {
            if ('/api/documents/' === $path) {
                $query = $q;

                return new JsonMockResponse(['next' => null, 'results' => []]);
            }

            return PaperlessClientTest::homelab($path, $q);
        });

        self::assertIsArray($query);
        self::assertArrayNotHasKey('custom_field_query', $query, 'sans champ « payée », aucune facture n\'est marquée payée');
    }

    public function testMissingAmountAndDueFieldsGiveUnknownValues(): void
    {
        $data = $this->fetch(['fields' => ['amount' => 'Champ absent', 'due' => 'Autre absent']]);

        self::assertSame([null, null, null], array_column($data['invoices'], 'amount'));
        self::assertSame([null, null, null], array_column($data['invoices'], 'dueDate'));
        self::assertSame(3, $data['missingAmounts']);
        self::assertSame(['Facture électricité octobre', 'Facture internet', 'Facture sans détails'], array_column($data['invoices'], 'title'), 'sans échéance : la plus ancienne d\'abord');
    }

    public function testOtherCurrenciesStayOutOfTheTotal(): void
    {
        $data = $this->fetch([], static fn (string $path, array $query) => '/api/documents/' === $path
            ? new JsonMockResponse(['next' => null, 'results' => [
                ['id' => 1, 'title' => 'A', 'custom_fields' => [['field' => 1, 'value' => 'EUR10.00']]],
                ['id' => 2, 'title' => 'B', 'custom_fields' => [['field' => 1, 'value' => 'EUR5.50']]],
                ['id' => 3, 'title' => 'C', 'custom_fields' => [['field' => 1, 'value' => 'USD99.00']]],
            ]])
            : PaperlessClientTest::homelab($path, $query));

        self::assertSame([15.5, 'EUR'], [$data['total'], $data['currency']]);
        self::assertSame('USD', $data['invoices'][2]['currency']);
    }

    public function testUnreachableSource(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/^Paperless injoignable/');

        $this->fetch([], static fn () => new MockResponse('', ['error' => 'Connection refused']));
    }

    public function testRejectedToken(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Jeton Paperless refusé (403), vérifier PAPERLESS_API_TOKEN');

        $this->fetch([], static fn () => new MockResponse('{"detail":"Permission refusée"}', ['http_code' => 403]));
    }
}
