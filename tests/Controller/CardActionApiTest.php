<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Actions des cartes (POST /api/cards/{id}/actions/{action}) : carte urssaf
 * de bob dans la fixture, Dagu fictif de FakeHomelab.
 */
final class CardActionApiTest extends WebTestCase
{
    private const SAME_SITE = ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function post(string $url, array $body, int $expected, array $server = self::SAME_SITE): array
    {
        $this->client->request('POST', $url, server: $server, content: json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertSame($expected, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testStart(): void
    {
        self::assertSame(['runId' => 'run-3'], $this->post('/api/cards/urssaf/actions/start', ['amount' => 0], 200));
    }

    public function testStop(): void
    {
        self::assertSame(['stopped' => 'run-2'], $this->post('/api/cards/urssaf/actions/stop', [], 200));
    }

    public function testRefusedActionGivesTheReason(): void
    {
        self::assertSame(['error' => 'Montant attendu : un nombre entier d\'euros, 0 ou plus.'], $this->post('/api/cards/urssaf/actions/start', ['amount' => -3], 409));
    }

    public function testUnknownActionOrCardWithoutActions(): void
    {
        $this->post('/api/cards/urssaf/actions/valider', [], 404);
        $this->post('/api/cards/recettes-alice/actions/start', [], 404);
        $this->post('/api/cards/inconnue/actions/start', [], 404);
    }

    public function testCrossSiteAndNonJsonRefused(): void
    {
        $this->post('/api/cards/urssaf/actions/start', ['amount' => 0], 403, ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $this->post('/api/cards/urssaf/actions/start', ['amount' => 0], 415, ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    public function testCardDataShowTheSession(): void
    {
        $this->client->request('GET', '/api/cards/urssaf');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('ok', $result['status']);
        self::assertSame('rempli', $result['data']['run']['step']);
        self::assertStringStartsWith('https://ecran.example/vnc.html?', $result['data']['run']['screenUrl']);
    }
}
