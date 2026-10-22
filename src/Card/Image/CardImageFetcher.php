<?php

namespace App\Card\Image;

use App\Card\CardTypeRegistry;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Relais d'images : vérifie la signature, demande l'image au type de carte et
 * la garde 24 h (pool de cache sur disque, les images sont lourdes).
 */
final readonly class CardImageFetcher
{
    private const TTL = 86400;
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private CardTypeRegistry $registry,
        private ImageUrlSigner $signer,
        #[Target('cache.images')]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws InvalidImageRequestException signature fausse ou carte sans images
     * @throws SourceUnavailableException   source injoignable, ou réponse qui n'est pas une image
     */
    public function fetch(CardInstance $card, Dashboard $dashboard, string $path, string $signature): CardImage
    {
        if (!$this->signer->verify($card->id, $path, $signature)) {
            throw new InvalidImageRequestException('Signature d\'image invalide.');
        }
        $type = $this->registry->get($card->type);
        if (!$type instanceof ImageSourceInterface) {
            throw new InvalidImageRequestException(\sprintf('La carte %s ne relaie pas d\'images.', $card->id));
        }

        return $this->cache->get('image.'.hash('xxh128', $card->id.'|'.$path), function (ItemInterface $item) use ($type, $path, $card, $dashboard): CardImage {
            $item->expiresAfter(self::TTL);
            $image = $type->fetchImage($path, $card, $dashboard);

            if (!str_starts_with($image->contentType, 'image/')) {
                throw new SourceUnavailableException(\sprintf('La réponse n\'est pas une image (%s)', $image->contentType));
            }
            if (\strlen($image->bytes) > self::MAX_BYTES) {
                throw new SourceUnavailableException('Image trop lourde pour être relayée (plus de 5 Mo)');
            }

            return $image;
        });
    }
}
