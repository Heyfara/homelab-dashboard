<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Card\Image\CardImage;
use App\Card\Image\ImageSourceInterface;
use App\Card\Image\ImageUrlSigner;
use App\Card\Image\InvalidImageRequestException;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\CalibreWeb\CalibreWebBook;
use App\Source\CalibreWeb\CalibreWebClient;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Derniers ebooks ajoutés à Calibre-Web (Automated), en slider. Les
 * couvertures exigent l'authentification : relais d'images signé.
 *
 *     - type: calibre-web
 *       service: ebooks
 *       span: 2
 *       rows: 2
 */
#[AsCard('calibre-web')]
final readonly class CalibreWebCard implements CardTypeInterface, ImageSourceInterface
{
    public function __construct(
        private CalibreWebClient $calibre,
        private ImageUrlSigner $signer,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setDefault('limit', 20)
            ->setAllowedTypes('limit', 'int')
            ->setAllowedValues('limit', static fn (int $n): bool => $n >= 1 && $n <= 50);
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return ['title' => $card->title ?? $service->name, 'service' => $service->name, 'url' => $service->url, 'icon' => $service->icon];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];
        $public = rtrim((string) $service->url, '/');

        return [
            'books' => array_map(fn (CalibreWebBook $b): array => [
                'id' => $b->id,
                'title' => $b->title,
                'author' => $b->author,
                'cover' => $b->hasCover ? $this->signer->url($card->id, (string) $b->id) : null,
                'link' => \sprintf('%s/book/%d', $public, $b->id),
                'addedAt' => $b->addedAt?->format(\DATE_ATOM),
            ], $this->calibre->recent($service->check ?? (string) $service->url, $card->options['limit'])),
        ];
    }

    public function fetchImage(string $path, CardInstance $card, Dashboard $dashboard): CardImage
    {
        if (!ctype_digit($path)) {
            throw new InvalidImageRequestException(\sprintf('Couverture Calibre-Web invalide : %s', $path));
        }
        $service = $dashboard->services[$card->options['service']];

        return $this->calibre->cover($service->check ?? (string) $service->url, (int) $path);
    }
}
