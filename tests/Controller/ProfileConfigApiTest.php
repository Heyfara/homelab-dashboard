<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * « Ma config » : réglages de chaque profil, ici le lieu de la météo (carte
 * meteo-profil de bob, dans la fixture ; les clés d'API ont leur propre test).
 */
final class ProfileConfigApiTest extends WebTestCase
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
    private function put(mixed $body, array $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], string $profile = 'bob'): array
    {
        $this->client->request('PUT', '/api/profiles/'.$profile.'/config/weather', server: $headers, content: \is_string($body) ? $body : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function config(string $profile = 'bob'): array
    {
        $this->client->request('GET', '/api/profiles/'.$profile.'/config');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function cardData(string $id): array
    {
        $this->client->request('GET', '/api/dashboards/bob');
        $this->client->request('GET', '/api/cards/'.$id);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testWeatherSectionShowsTheYamlPlaceUntilOneIsChosen(): void
    {
        self::assertSame(['place' => 'Village fictif', 'latitude' => 45, 'longitude' => 5, 'custom' => false, 'updatedAt' => null], $this->config()['weather']);
        self::assertSame(['credentials' => [], 'weather' => null], $this->config('alice'), 'rien à configurer chez alice');
    }

    public function testChosenPlaceIsUsedByTheCard(): void
    {
        $json = $this->put(['place' => 'Brest', 'latitude' => 48.39046, 'longitude' => -4.48628]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49, 'custom' => true], array_diff_key($json, ['updatedAt' => 1]));
        self::assertSame($json, $this->config()['weather']);

        $this->client->request('GET', '/api/dashboards/bob');
        $cards = json_decode((string) $this->client->getResponse()->getContent(), true)['sections'][0]['cards'];
        $card = array_values(array_filter($cards, static fn (array $c): bool => 'meteo-profil' === $c['id']))[0];
        self::assertSame(['title' => 'Brest', 'place' => 'Brest'], $card['props']);
        self::assertSame('ok', $this->cardData('meteo-profil')['status']);
    }

    public function testResetGoesBackToTheYamlPlace(): void
    {
        $this->put(['place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49]);
        $this->client->request('DELETE', '/api/profiles/bob/config/weather', server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertSame('Village fictif', $this->config()['weather']['place']);
    }

    public function testInvalidPlaceRefusedWithTheReason(): void
    {
        $json = $this->put(['place' => 'Brest', 'latitude' => 120, 'longitude' => -4.49]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('Latitude attendue : un nombre entre -90 et 90.', $json['error']);
        self::assertFalse($this->config()['weather']['custom']);
    }

    public function testProfileWithoutConfigurableWeatherCard(): void
    {
        $this->put(['place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49], profile: 'alice');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRequestsFromAnotherSiteAreRefused(): void
    {
        $this->put(['place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49], ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', '/api/profiles/bob/config/weather', server: ['HTTP_ORIGIN' => 'https://pirate.example']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->put('place=Brest', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertSame(415, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownProfile(): void
    {
        $this->client->request('GET', '/api/profiles/inconnu/config');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testPlaceSearch(): void
    {
        $this->client->request('GET', '/api/places?q=Lyon');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            ['name' => 'Lyon', 'region' => 'Rhône', 'country' => 'France', 'latitude' => 45.74846, 'longitude' => 4.84671],
            json_decode((string) $this->client->getResponse()->getContent(), true)[0],
        );
    }

    public function testPlaceSearchNeedsTwoLetters(): void
    {
        $this->client->request('GET', '/api/places?q=%20L%20');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('[]', $this->client->getResponse()->getContent(), 'pas de requête vers Open-Meteo');
    }
}
