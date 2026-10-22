<?php

namespace App\Tests\Card\Type;

use App\Card\Image\CardImage;
use App\Card\Image\ImageUrlSigner;
use App\Card\Image\InvalidImageRequestException;
use App\Card\Type\CalibreWebCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\CalibreWeb\CalibreWebClient;
use App\Tests\Source\CalibreWeb\CalibreWebClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CalibreWebCardTest extends TestCase
{
    private Dashboard $dashboard;
    private ImageUrlSigner $signer;
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'livres' => new ServiceDefinition('livres', 'Ebooks', url: 'https://livres.example', check: 'http://cwa:8083', icon: 'sh-calibre-web'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
        $this->signer = new ImageUrlSigner('secret');
    }

    private function card(): CalibreWebCard
    {
        return new CalibreWebCard(new CalibreWebClient(new MockHttpClient(function (string $method, string $url) {
            $this->urls[] = $url;
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return CalibreWebClientTest::homelab((string) parse_url($url, \PHP_URL_PATH), $query);
        }), 'lecteur', 'mdp'), $this->signer);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(CalibreWebCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('e', 'calibre-web', null, 2, 300, $resolver->resolve(['service' => 'livres', ...$options]), 2);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'livres', 'limit' => 20], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Ebooks', 'service' => 'Ebooks', 'url' => 'https://livres.example', 'icon' => 'sh-calibre-web'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testRecentBooksWithRelayedCovers(): void
    {
        $card = $this->card();
        $books = $card->fetch($this->instance($card), $this->dashboard)['books'];

        self::assertSame([
            'id' => 12,
            'title' => 'Le Phare des brumes',
            'author' => 'Jeanne Fictive, Paul Inventé',
            'cover' => $this->signer->url('e', '12'),
            'link' => 'https://livres.example/book/12',
            'addedAt' => '2026-10-18T20:35:19+00:00',
        ], $books[0]);
        self::assertNull($books[1]['cover'], 'pas de couverture : pas d\'image relayée');
        self::assertCount(3, $books);
    }

    public function testFetchCoverThroughTheApiAddress(): void
    {
        $card = $this->card();

        self::assertEquals(new CardImage('octets', 'image/webp'), $card->fetchImage('12', $this->instance($card), $this->dashboard));
        self::assertSame('http://cwa:8083/opds/cover/12', $this->urls[0]);
    }

    public function testFetchImageRejectsAnythingButABookId(): void
    {
        $card = $this->card();

        $this->expectException(InvalidImageRequestException::class);
        $card->fetchImage('../admin', $this->instance($card), $this->dashboard);
    }
}
