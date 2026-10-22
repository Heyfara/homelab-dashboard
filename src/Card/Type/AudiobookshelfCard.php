<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Audiobookshelf\AudiobookshelfBook;
use App\Source\Audiobookshelf\AudiobookshelfClient;
use App\Source\Audiobookshelf\AudiobookshelfProgress;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Livres audio : « Reprendre l'écoute » (progression d'un utilisateur) et
 * derniers ajouts en slider. Couvertures publiques.
 *
 *     - type: audiobookshelf
 *       service: audiobooks
 *       user: hugo       # sinon le propriétaire de la clé
 *       span: 2
 *       rows: 2
 */
#[AsCard('audiobookshelf')]
final readonly class AudiobookshelfCard implements CardTypeInterface
{
    /** Écoutes en cours renvoyées au plus. */
    private const MAX_LISTENING = 6;

    public function __construct(
        private AudiobookshelfClient $abs,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setDefault('user', null)
            ->setAllowedTypes('user', ['null', 'string'])
            ->setInfo('user', 'utilisateur Audiobookshelf dont on suit l\'écoute ; sinon le propriétaire de la clé')
            ->setDefault('limit', 12)
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
        $api = $service->check ?? (string) $service->url;
        $public = rtrim((string) $service->url, '/');
        $cover = static fn (string $id): string => \sprintf('%s/api/items/%s/cover', $public, $id);
        $link = static fn (string $id): string => \sprintf('%s/item/%s', $public, $id);

        [$recent, $total] = $this->abs->recent($api, $card->options['limit']);
        $byId = [];
        foreach ($recent as $book) {
            $byId[$book->id] = $book;
        }

        $listening = array_map(function (AudiobookshelfProgress $p) use ($api, $byId, $cover, $link): array {
            $book = $byId[$p->itemId] ?? $this->abs->book($api, $p->itemId);

            return [
                'id' => $p->itemId,
                'title' => $book->title,
                'author' => $book->author,
                'cover' => $cover($p->itemId),
                'link' => $link($p->itemId),
                'progress' => $p->progress,
                'remaining' => max(0, $p->duration - $p->currentTime),
                'lastPlayed' => $p->lastUpdate->format(\DATE_ATOM),
            ];
        }, \array_slice($this->abs->progress($api, $card->options['user']), 0, self::MAX_LISTENING));

        return [
            'listening' => $listening,
            'recent' => array_map(static fn (AudiobookshelfBook $b): array => [
                'id' => $b->id,
                'title' => $b->title,
                'author' => $b->author,
                'cover' => $cover($b->id),
                'link' => $link($b->id),
                'duration' => $b->duration,
            ], $recent),
            'total' => $total,
        ];
    }
}
