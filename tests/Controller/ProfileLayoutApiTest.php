<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ordre des cartes choisi par chaque profil (alice, dans la fixture).
 */
final class ProfileLayoutApiTest extends WebTestCase
{
    private const SAME_SITE = ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $dir = static::getContainer()->getParameter('kernel.cache_dir').'/profiles';
        array_map('unlink', glob($dir.'/{,.}*.*', \GLOB_BRACE) ?: []);
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(): array
    {
        $this->client->request('GET', '/api/dashboards/alice');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @return list<string>
     */
    private function firstSection(): array
    {
        return array_column($this->dashboard()['sections'][0]['cards'], 'id');
    }

    /**
     * @param array<string, string> $headers
     */
    private function put(mixed $body, array $headers = self::SAME_SITE): int
    {
        $this->client->request('PUT', '/api/profiles/alice/layout', server: $headers, content: \is_string($body) ? $body : json_encode($body));

        return $this->client->getResponse()->getStatusCode();
    }

    public function testSavedOrderIsServedWithTheDashboard(): void
    {
        [$photos, $recettes] = $this->firstSection();
        self::assertFalse($this->dashboard()['customized']);

        self::assertSame(204, $this->put(['order' => [$recettes, $photos]]));

        self::assertSame([$recettes, $photos], $this->firstSection());
        self::assertTrue($this->dashboard()['customized']);
    }

    public function testResetGoesBackToTheYamlOrder(): void
    {
        [$photos, $recettes] = $this->firstSection();
        $this->put(['order' => [$recettes, $photos]]);

        $this->client->request('DELETE', '/api/profiles/alice/layout', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertSame([$photos, $recettes], $this->firstSection());
        self::assertFalse($this->dashboard()['customized']);
    }

    public function testUnknownCardIsRefused(): void
    {
        self::assertSame(422, $this->put(['order' => ['recettes-alice', 'carte-de-bob']]));
        self::assertStringContainsString('carte-de-bob', (string) $this->client->getResponse()->getContent());
    }

    public function testMalformedOrderIsRefused(): void
    {
        self::assertSame(422, $this->put(['order' => 'recettes-alice']));
        self::assertSame(422, $this->put(['order' => [1, 2]]));
        self::assertSame(415, $this->put('order=a', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_SEC_FETCH_SITE' => 'same-origin']));
    }

    public function testCrossSiteWritesAreRefused(): void
    {
        self::assertSame(403, $this->put(['order' => ['recettes-alice']], ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'cross-site']));
        self::assertSame(403, $this->put(['order' => ['recettes-alice']], ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://pirate.example']));
        $this->client->request('DELETE', '/api/profiles/alice/layout', server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownProfile(): void
    {
        $this->client->request('PUT', '/api/profiles/inconnu/layout', server: self::SAME_SITE, content: '{"order":[]}');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
