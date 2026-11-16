<?php

namespace App\Tests\Source\Paperless;

use App\Source\Paperless\PaperlessClient;
use App\Source\Paperless\PaperlessCustomField;
use App\Source\Paperless\PaperlessDocument;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PaperlessClientTest extends TestCase
{
    private const BASE = 'http://10.0.0.14:8000';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    private function client(?callable $respond = null, string $token = 'jeton'): PaperlessClient
    {
        $respond ??= static fn (string $path, array $query) => self::homelab($path, $query);

        return new PaperlessClient(new MockHttpClient(function (string $method, string $url, array $options) use ($respond) {
            $this->requests[] = [$method, $url, $options];
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return $respond((string) parse_url($url, \PHP_URL_PATH), $query);
        }), $token);
    }

    /**
     * Paperless fictif, réutilisé par FakeHomelab et le test de la carte : trois
     * factures non payées (une sans montant ni échéance, une en dollars sans
     * préfixe de devise) ; le filtre « non payée » est vérifié ici.
     *
     * @param array<string, mixed> $query
     */
    public static function homelab(string $path, array $query = []): MockResponse
    {
        return match ($path) {
            '/api/custom_fields/' => new JsonMockResponse(['count' => 5, 'next' => null, 'results' => [
                ['id' => 1, 'name' => 'Montant', 'data_type' => 'monetary', 'extra_data' => ['default_currency' => null]],
                ['id' => 2, 'name' => 'Échéance', 'data_type' => 'date', 'extra_data' => []],
                ['id' => 3, 'name' => 'Payée le', 'data_type' => 'date', 'extra_data' => []],
                ['id' => 4, 'name' => 'Transaction Actual', 'data_type' => 'string', 'extra_data' => []],
                ['id' => 5, 'name' => 'Montant USD', 'data_type' => 'monetary', 'extra_data' => ['default_currency' => 'USD']],
            ]]),
            '/api/documents/' => self::documents($query),
            '/api/correspondents/' => new JsonMockResponse(['count' => 2, 'next' => null, 'results' => [
                ['id' => 7, 'name' => 'Fournisseur d\'énergie fictif'],
                ['id' => 8, 'name' => 'Opérateur fictif'],
            ]]),
            default => throw new \LogicException('Requête Paperless non prévue : '.$path),
        };
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function documents(array $query): MockResponse
    {
        if ('Facture' !== ($query['document_type__name__iexact'] ?? null)) {
            return new JsonMockResponse(['count' => 0, 'next' => null, 'results' => []]);
        }
        if ('["OR",[[3,"exists",false],[3,"isnull",true]]]' !== ($query['custom_field_query'] ?? null)) {
            throw new \LogicException('Filtre « non payée » inattendu : '.json_encode($query));
        }

        return new JsonMockResponse(['count' => 3, 'next' => null, 'results' => [
            ['id' => 41, 'title' => 'Facture électricité octobre', 'created' => '2026-10-28', 'correspondent' => 7, 'custom_fields' => [
                ['field' => 1, 'value' => 'EUR246.50'],
                ['field' => 2, 'value' => '2026-11-20'],
                ['field' => 3, 'value' => null],
            ]],
            ['id' => 42, 'title' => 'Facture internet', 'created' => '2026-11-02', 'correspondent' => 8, 'custom_fields' => [
                ['field' => 1, 'value' => '39.99'],
                ['field' => 2, 'value' => '2026-11-10'],
            ]],
            ['id' => 43, 'title' => 'Facture sans détails', 'created' => '2026-11-05', 'correspondent' => null, 'custom_fields' => []],
        ]]);
    }

    public function testCustomFields(): void
    {
        $fields = $this->client()->customFields(self::BASE);

        self::assertEquals(new PaperlessCustomField(1, 'Montant', 'monetary', null), $fields[0]);
        self::assertEquals(new PaperlessCustomField(5, 'Montant USD', 'monetary', 'USD'), $fields[4]);

        [$method, $url, $options] = $this->requests[0];
        self::assertSame('GET', $method);
        self::assertStringStartsWith(self::BASE.'/api/custom_fields/?', $url);
        self::assertContains('Authorization: Token jeton', $options['headers']);
        self::assertContains('Accept: application/json; version=9', $options['headers']);
    }

    public function testDocumentsOfATypeWithCustomFieldQuery(): void
    {
        $documents = $this->client()->documents(self::BASE, 'Facture', ['OR', [[3, 'exists', false], [3, 'isnull', true]]]);

        self::assertEquals(new PaperlessDocument(41, 'Facture électricité octobre', '2026-10-28', 7, [1 => 'EUR246.50', 2 => '2026-11-20', 3 => null]), $documents[0]);
        self::assertEquals(new PaperlessDocument(43, 'Facture sans détails', '2026-11-05', null, []), $documents[2]);

        parse_str((string) parse_url($this->requests[0][1], \PHP_URL_QUERY), $query);
        self::assertSame('Facture', $query['document_type__name__iexact']);
        self::assertSame('id,title,created,correspondent,custom_fields', $query['fields']);
        self::assertSame('100', $query['page_size']);
    }

    public function testDocumentsWithoutCustomFieldQuery(): void
    {
        $this->client(static fn () => new JsonMockResponse(['next' => null, 'results' => []]))->documents(self::BASE, 'Facture', null);

        parse_str((string) parse_url($this->requests[0][1], \PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey('custom_field_query', $query);
    }

    public function testFollowsPages(): void
    {
        $client = $this->client(static fn (string $path, array $query) => new JsonMockResponse('1' === $query['page']
            ? ['next' => 'https://papiers.example/api/documents/?page=2', 'results' => [['id' => 1, 'title' => 'A']]]
            : ['next' => null, 'results' => [['id' => 2, 'title' => 'B']]]));

        self::assertSame([1, 2], array_map(static fn (PaperlessDocument $d): int => $d->id, $client->documents(self::BASE, 'Facture', null)));
        self::assertCount(2, $this->requests);
    }

    public function testCorrespondentNames(): void
    {
        self::assertSame([7 => 'Fournisseur d\'énergie fictif', 8 => 'Opérateur fictif'], $this->client()->correspondents(self::BASE));
    }

    public function testOnlyReads(): void
    {
        $client = $this->client();
        $client->customFields(self::BASE);
        $client->documents(self::BASE, 'Facture', ['OR', [[3, 'exists', false], [3, 'isnull', true]]]);
        $client->correspondents(self::BASE);

        self::assertSame(['GET'], array_values(array_unique(array_column($this->requests, 0))));
    }

    public function testRejectedToken(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Jeton Paperless refusé (401), vérifier PAPERLESS_API_TOKEN');

        $this->client(static fn () => new MockResponse('{"detail":"Invalid token."}', ['http_code' => 401]))->customFields(self::BASE);
    }

    public function testMissingToken(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Jeton Paperless absent (PAPERLESS_API_TOKEN)');

        $this->client(static fn () => throw new \LogicException('aucun appel attendu'), '')->customFields(self::BASE);
    }

    public function testRejectedRequestIsExplained(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Paperless refuse la requête (400) : {"custom_field_query":["Invalid custom field query"]}');

        $this->client(static fn () => new MockResponse('{"custom_field_query":["Invalid custom field query"]}', ['http_code' => 400]))->documents(self::BASE, 'Facture', ['OR', []]);
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/^Paperless injoignable : .*Connection refused/');

        $this->client(static fn () => new MockResponse('', ['error' => 'Connection refused']))->customFields(self::BASE);
    }
}
