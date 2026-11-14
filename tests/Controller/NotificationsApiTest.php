<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Messages ntfy des sujets suivis par un profil (cloche) et ce qu'il a lu.
 * Fixture : alice suit « urgent » et « maison », bob rien ; ntfy fictif dans
 * FakeHomelab (NtfyClientTest::stream()).
 */
final class NotificationsApiTest extends WebTestCase
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
    private function get(string $profile = 'alice'): array
    {
        $this->client->request('GET', "/api/profiles/$profile/notifications");

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @param array<string, string> $headers
     */
    private function markRead(mixed $body, array $headers = self::SAME_SITE): int
    {
        $this->client->request('POST', '/api/profiles/alice/notifications/read', server: $headers, content: \is_string($body) ? $body : json_encode($body));

        return $this->client->getResponse()->getStatusCode();
    }

    public function testListsTheMessagesOfTheProfileTopicsNewestFirst(): void
    {
        $body = $this->get();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([['id' => 'urgent', 'name' => 'Urgent'], ['id' => 'maison', 'name' => 'Maison']], $body['topics']);
        self::assertSame('https://ntfy.example', $body['link']);
        self::assertSame(['m3', 'm2', 'm1'], array_column($body['messages'], 'id'));
        self::assertSame([
            'id' => 'm2',
            'time' => (new \DateTimeImmutable('@1792400200'))->setTimezone(new \DateTimeZone('Europe/Paris'))->format(\DATE_ATOM),
            'topic' => 'urgent',
            'title' => 'Fumée détectée',
            'message' => 'Salon',
            'priority' => 5,
            'tags' => ['rotating_light'],
            'click' => 'https://maison.test/',
            'read' => false,
        ], $body['messages'][1]);
        self::assertSame(3, $body['unread']);
    }

    public function testReadMessagesStayReadForTheProfile(): void
    {
        self::assertSame(204, $this->markRead(['ids' => ['m2', 'm3']]));

        $body = $this->get();
        self::assertSame([true, true, false], array_column($body['messages'], 'read'));
        self::assertSame(1, $body['unread']);
    }

    public function testProfileWithoutTopicsHasNoNotifications(): void
    {
        $this->get('bob');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDashboardSaysWhetherTheProfileHasNotifications(): void
    {
        $this->client->request('GET', '/api/dashboards/alice');
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['profile']['notifications']);

        $this->client->request('GET', '/api/dashboards/bob');
        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['profile']['notifications']);
    }

    public function testMarkingReadNeedsAListOfIds(): void
    {
        self::assertSame(422, $this->markRead(['ids' => 'm1']));
        self::assertSame(422, $this->markRead(['ids' => [1, 2]]));
        self::assertSame(415, $this->markRead('ids=m1', ['HTTP_SEC_FETCH_SITE' => 'same-origin']));
    }

    public function testMarkingReadFromAnotherSiteIsRefused(): void
    {
        self::assertSame(403, $this->markRead(['ids' => ['m1']], ['CONTENT_TYPE' => 'application/json', 'HTTP_SEC_FETCH_SITE' => 'cross-site']));
    }
}
