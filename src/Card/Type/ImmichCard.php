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
use App\Source\Immich\ImmichAsset;
use App\Source\Immich\ImmichClient;
use App\Source\Immich\ImmichMemory;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Photos Immich du propriétaire de la clé : souvenirs « ce jour-là » et
 * dernières photos en slider. Les miniatures exigent la clé : elles passent
 * par le relais d'images signé du dashboard.
 *
 *     - type: immich
 *       service: immich
 *       span: 2
 *       rows: 2
 */
#[AsCard('immich')]
final readonly class ImmichCard implements CardTypeInterface, ImageSourceInterface
{
    public function __construct(
        private ImmichClient $immich,
        private ImageUrlSigner $signer,
        /** Date du jour (AAAA-MM-JJ), pour les tests ; sinon aujourd'hui à Paris */
        private ?string $today = null,
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
            ->setAllowedValues('limit', static fn (int $n): bool => $n >= 1 && $n <= 100)
            ->setDefault('memories', true)
            ->setAllowedTypes('memories', 'bool')
            ->setInfo('memories', 'souvenirs « ce jour-là » au-dessus des dernières photos');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return ['title' => $card->title ?? $service->name, 'service' => $service->name, 'url' => $service->url, 'icon' => $service->icon];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];
        $api = $service->check ?? (string) $service->url;
        $public = rtrim((string) $service->url, '/');
        $today = new \DateTimeImmutable($this->today ?? 'today', new \DateTimeZone('Europe/Paris'));
        $image = fn (string $assetId, string $size): string => $this->signer->url($card->id, $assetId.'/'.$size);

        $memories = $card->options['memories'] ? $this->immich->memories($api, $today) : [];

        return [
            'stats' => $this->immich->statistics($api),
            'memories' => array_map(static fn (ImmichMemory $m): array => [
                'id' => $m->id,
                'year' => $m->year,
                'yearsAgo' => (int) $today->format('Y') - $m->year,
                'count' => \count($m->assets),
                'cover' => $image($m->assets[0]->id, 'preview'),
                'link' => \sprintf('%s/memories/%s', $public, rawurlencode($m->id)),
            ], $memories),
            'recent' => array_map(static fn (ImmichAsset $a): array => [
                'id' => $a->id,
                'video' => $a->video,
                'takenAt' => $a->takenAt?->format('Y-m-d\TH:i:s'),
                'thumb' => $image($a->id, 'thumbnail'),
                'link' => \sprintf('%s/photos/%s', $public, $a->id),
            ], $this->immich->recent($api, $card->options['limit'])),
        ];
    }

    public function fetchImage(string $path, CardInstance $card, Dashboard $dashboard): CardImage
    {
        $service = $dashboard->services[$card->options['service']];
        [$assetId, $size] = explode('/', $path, 2) + [1 => ''];

        try {
            return $this->immich->thumbnail($service->check ?? (string) $service->url, $assetId, $size);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidImageRequestException($e->getMessage(), previous: $e);
        }
    }
}
