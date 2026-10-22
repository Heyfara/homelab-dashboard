<?php

namespace App\Tests\Card\Type;

use App\Card\Image\CardImage;
use App\Card\Image\ImageUrlSigner;
use App\Card\Type\ImmichCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Immich\ImmichClient;
use App\Tests\Source\Immich\ImmichClientTest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ImmichCardTest extends TestCase
{
    private const P1 = '0a3c1d6e-5b2f-4c8a-9d1e-2f3a4b5c6d71';
    private const P2 = '0a3c1d6e-5b2f-4c8a-9d1e-2f3a4b5c6d72';
    private const V1 = '0a3c1d6e-5b2f-4c8a-9d1e-2f3a4b5c6d73';

    private Dashboard $dashboard;
    private ImageUrlSigner $signer;
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'photos' => new ServiceDefinition('photos', 'Immich', url: 'https://photos.example', check: 'http://immich:2283', icon: 'sh-immich'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
        $this->signer = new ImageUrlSigner('secret');
    }

    private function card(): ImmichCard
    {
        return new ImmichCard(new ImmichClient(new MockHttpClient(function (string $method, string $url) {
            $this->urls[] = $url;

            return ImmichClientTest::homelab((string) parse_url($url, \PHP_URL_PATH));
        }), 'cle'), $this->signer, '2026-10-20');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(ImmichCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('i', 'immich', null, 2, 300, $resolver->resolve(['service' => 'photos', ...$options]), 2);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function fetch(array $options = []): array
    {
        $card = $this->card();

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptions(): void
    {
        self::assertEquals(['service' => 'photos', 'limit' => 20, 'memories' => true], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'Immich', 'service' => 'Immich', 'url' => 'https://photos.example', 'icon' => 'sh-immich'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testRecentWithRelayedThumbnails(): void
    {
        $data = $this->fetch();

        self::assertSame(['images' => 1520, 'videos' => 87], $data['stats']);
        self::assertSame([
            'id' => self::P1,
            'video' => false,
            'takenAt' => '2026-10-19T16:42:10',
            'thumb' => $this->signer->url('i', self::P1.'/thumbnail'),
            'link' => 'https://photos.example/photos/'.self::P1,
        ], $data['recent'][0]);
        self::assertTrue($data['recent'][1]['video']);
    }

    public function testMemoriesOfTheDay(): void
    {
        $memories = $this->fetch()['memories'];

        self::assertSame([
            'id' => 'mem-2023',
            'year' => 2023,
            'yearsAgo' => 3,
            'count' => 2,
            'cover' => $this->signer->url('i', self::P2.'/preview'),
            'link' => 'https://photos.example/memories/mem-2023',
        ], $memories[0]);
        self::assertSame(7, $memories[1]['yearsAgo']);
    }

    public function testMemoriesCanBeTurnedOff(): void
    {
        self::assertSame([], $this->fetch(['memories' => false])['memories']);
        foreach ($this->urls as $url) {
            self::assertStringNotContainsString('/memories', $url);
        }
    }

    public function testFetchImageThroughTheApiAddress(): void
    {
        $card = $this->card();

        self::assertEquals(new CardImage('octets', 'image/webp'), $card->fetchImage(self::V1.'/thumbnail', $this->instance($card), $this->dashboard));
        self::assertStringStartsWith('http://immich:2283/api/assets/'.self::V1.'/thumbnail', $this->urls[0]);
    }
}
