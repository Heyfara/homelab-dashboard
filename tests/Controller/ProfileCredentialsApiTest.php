<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « Mes clés » : clés d'API saisies par chaque profil (bob, dans la fixture).
 */
final class ProfileCredentialsApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $dir = static::getContainer()->getParameter('kernel.cache_dir').'/profiles';
        array_map('unlink', glob($dir.'/{,.}*.*', \GLOB_BRACE) ?: []);
    }

    /**
     * @param array<string, string> $headers
     */
    private function put(string $url, mixed $body, array $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin']): array
    {
        $this->client->request('PUT', $url, server: $headers, content: \is_string($body) ? $body : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function list(string $profile = 'bob'): array
    {
        $this->client->request('GET', '/api/profiles/'.$profile.'/credentials');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testListsTheServicesWithoutAnyKey(): void
    {
        self::assertSame([['id' => 'gw2', 'label' => 'Guild Wars 2', 'help' => 'Créer une clé sur https://account.arena.net/applications en cochant : account, wallet, progression, tradingpost.', 'configured' => false, 'account' => null, 'updatedAt' => null]], $this->list());
    }

    public function testSaveVerifiesTheKeyAndNeverReturnsIt(): void
    {
        $json = $this->put('/api/profiles/bob/credentials/gw2', ['key' => 'CLE-DE-BOB']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['configured' => true, 'account' => 'Joueur.1234'], array_intersect_key($json, ['configured' => 1, 'account' => 1]));
        self::assertStringNotContainsString('CLE-DE-BOB', (string) $this->client->getResponse()->getContent());

        $listed = $this->list()[0];
        self::assertTrue($listed['configured']);
        self::assertSame('Joueur.1234', $listed['account']);
        self::assertStringNotContainsString('CLE-DE-BOB', json_encode($this->list()));
        self::assertFalse($this->list('alice')[0]['configured'], 'chaque profil a ses clés');
    }

    public function testCardWithProfileCredentialsUsesTheSavedKey(): void
    {
        $this->put('/api/profiles/bob/credentials/gw2', ['key' => 'CLE-DE-BOB']);

        $this->client->request('GET', '/api/dashboards/bob');
        $cards = json_decode((string) $this->client->getResponse()->getContent(), true)['sections'][0]['cards'];
        $card = array_values(array_filter($cards, static fn (array $c): bool => 'gw2-profil' === $c['id']))[0];
        $this->client->request('GET', '/api/cards/'.$card['id']);
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('ok', $json['status'], (string) $json['error']);
        self::assertSame('Joueur.1234', $json['data']['account']['name']);
    }

    public function testCardWithProfileCredentialsWithoutKeySaysWhatToDo(): void
    {
        $this->client->request('GET', '/api/cards/gw2-profil');
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('error', $json['status']);
        self::assertStringContainsString('« Mes clés »', $json['error']);
    }

    public function testInvalidKeyIsRefusedWithTheReason(): void
    {
        $json = $this->put('/api/profiles/bob/credentials/gw2', ['key' => '   ']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('Clé vide.', $json['error']);
        self::assertFalse($this->list()[0]['configured']);
    }

    public function testDelete(): void
    {
        $this->put('/api/profiles/bob/credentials/gw2', ['key' => 'CLE-DE-BOB']);
        $this->client->request('DELETE', '/api/profiles/bob/credentials/gw2', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->list()[0]['configured']);
    }

    public function testRequestsFromAnotherSiteAreRefused(): void
    {
        $this->put('/api/profiles/bob/credentials/gw2', ['key' => 'X'], ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->put('/api/profiles/bob/credentials/gw2', ['key' => 'X'], ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://pirate.example']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', '/api/profiles/bob/credentials/gw2', server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testJsonOnly(): void
    {
        $this->put('/api/profiles/bob/credentials/gw2', 'key=X', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertSame(415, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownProfileOrService(): void
    {
        $this->client->request('GET', '/api/profiles/inconnu/credentials');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->put('/api/profiles/bob/credentials/inconnu', ['key' => 'X']);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
