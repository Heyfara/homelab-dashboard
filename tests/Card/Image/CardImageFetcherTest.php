<?php

namespace App\Tests\Card\Image;

use App\Card\CardTypeInterface;
use App\Card\CardTypeRegistry;
use App\Card\Image\CardImage;
use App\Card\Image\CardImageFetcher;
use App\Card\Image\ImageUrlSigner;
use App\Card\Image\InvalidImageRequestException;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use App\Source\SourceUnavailableException;
use App\Tests\Card\FakeCard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CardImageFetcherTest extends TestCase
{
    private FakeCard $type;
    private ImageUrlSigner $signer;
    private CardImageFetcher $fetcher;
    private Dashboard $dashboard;
    private CardInstance $card;

    protected function setUp(): void
    {
        $this->type = new FakeCard();
        $this->signer = new ImageUrlSigner('secret');
        $registry = new CardTypeRegistry(new ServiceLocator(['fake' => fn () => $this->type, 'sans-images' => static fn () => new class implements CardTypeInterface {
            public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
            {
            }

            public function describe(CardInstance $card, Dashboard $dashboard): array
            {
                return [];
            }

            public function fetch(CardInstance $card, Dashboard $dashboard): array
            {
                return [];
            }
        }]));
        $this->fetcher = new CardImageFetcher($registry, $this->signer, new ArrayAdapter());
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [], []);
        $this->card = new CardInstance('c1', 'fake', null, 1, 30, ['label' => 'x']);
    }

    private function sig(string $path, string $card = 'c1'): string
    {
        parse_str((string) parse_url($this->signer->url($card, $path), \PHP_URL_QUERY), $query);

        return $query['sig'];
    }

    public function testFetchesAndCachesTheImage(): void
    {
        $image = $this->fetcher->fetch($this->card, $this->dashboard, '/ok.png', $this->sig('/ok.png'));
        $this->fetcher->fetch($this->card, $this->dashboard, '/ok.png', $this->sig('/ok.png'));

        self::assertEquals(new CardImage('PNG-OCTETS', 'image/png'), $image);
        self::assertSame(1, $this->type->imageCount);
    }

    public function testRejectsAnUnsignedPath(): void
    {
        $this->expectException(InvalidImageRequestException::class);

        $this->fetcher->fetch($this->card, $this->dashboard, '/ok.png', 'signature-inventee');
    }

    public function testRejectsACardWithoutImages(): void
    {
        $this->expectException(InvalidImageRequestException::class);

        $card = new CardInstance('c2', 'sans-images', null, 1, 30, []);
        $this->fetcher->fetch($card, $this->dashboard, '/ok.png', $this->sig('/ok.png', 'c2'));
    }

    public function testRejectsWhatIsNotAnImage(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage("n'est pas une image (text/html)");

        $this->fetcher->fetch($this->card, $this->dashboard, '/page.html', $this->sig('/page.html'));
    }

    public function testRejectsTooLargeImages(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('trop lourde');

        $this->fetcher->fetch($this->card, $this->dashboard, '/enorme.jpg', $this->sig('/enorme.jpg'));
    }
}
