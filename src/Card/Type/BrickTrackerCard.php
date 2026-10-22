<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\BrickTracker\BrickSet;
use App\Source\BrickTracker\BrickTrackerClient;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Collection Lego de BrickTracker : totaux (sets, pièces, minifigs, pièces
 * manquantes) et derniers sets ajoutés en slider. Images publiques.
 *
 *     - type: bricktracker
 *       service: bricktracker
 *       span: 2
 *       rows: 2
 */
#[AsCard('bricktracker')]
final readonly class BrickTrackerCard implements CardTypeInterface
{
    public function __construct(
        private BrickTrackerClient $bricks,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl));
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
        $stats = $this->bricks->statistics($api);

        return [
            'stats' => [
                'sets' => $stats->sets,
                'parts' => $stats->parts,
                'minifigures' => $stats->minifigures,
                'missingParts' => $stats->missingParts,
                'damagedParts' => $stats->damagedParts,
            ],
            'sets' => array_map(static fn (BrickSet $s): array => [
                'id' => $s->id,
                'number' => $s->number,
                'name' => $s->name,
                'theme' => $s->theme,
                'parts' => $s->parts,
                'minifigures' => $s->minifigures,
                'image' => $public.$s->image,
                'link' => \sprintf('%s/sets/%s/details', $public, rawurlencode($s->id)),
            ], $this->bricks->latestSets($api)),
        ];
    }
}
