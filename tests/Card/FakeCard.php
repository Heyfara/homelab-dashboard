<?php

namespace App\Tests\Card;

use App\Card\CardFeedsInterface;
use App\Card\CardTypeInterface;
use App\Card\Image\CardImage;
use App\Card\Image\ImageSourceInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Type de carte des tests unitaires : une option obligatoire « label », et
 * « service » optionnelle qui doit exister dans le catalogue. Un flux
 * supplémentaire « live », rafraîchi toutes les 5 s.
 */
final class FakeCard implements CardTypeInterface, CardFeedsInterface, ImageSourceInterface
{
    public int $fetchCount = 0;

    public int $feedCount = 0;

    public int $imageCount = 0;

    public ?\Throwable $failWith = null;

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $resolver
            ->setRequired('label')
            ->setAllowedTypes('label', 'string')
            ->setDefault('service', null)
            ->setAllowedValues('service', [null, ...array_keys($dashboard->services)])
            ->setDefault('loud', false)
            ->setAllowedTypes('loud', 'bool');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return ['label' => $card->options['label']];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        ++$this->fetchCount;
        if (null !== $this->failWith) {
            throw $this->failWith;
        }

        return ['count' => $this->fetchCount, 'loud' => $card->options['loud']];
    }

    public function feeds(CardInstance $card): array
    {
        return ['live' => 5];
    }

    public function fetchFeed(string $feed, CardInstance $card, Dashboard $dashboard): array
    {
        ++$this->feedCount;

        return ['feed' => $feed, 'count' => $this->feedCount];
    }

    public function fetchImage(string $path, CardInstance $card, Dashboard $dashboard): CardImage
    {
        ++$this->imageCount;

        return match ($path) {
            '/page.html' => new CardImage('<html>', 'text/html'),
            '/enorme.jpg' => new CardImage(str_repeat('x', 5 * 1024 * 1024 + 1), 'image/jpeg'),
            default => new CardImage('PNG-OCTETS', 'image/png'),
        };
    }
}
