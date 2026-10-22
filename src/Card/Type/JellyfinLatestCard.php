<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardFeedsInterface;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Jellyfin\JellyfinClient;
use App\Source\Jellyfin\JellyfinItem;
use App\Source\Jellyfin\JellyfinSession;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Derniers ajouts d'une médiathèque Jellyfin, avec leurs affiches, et lectures
 * en cours (tous utilisateurs) dans un flux rafraîchi plus souvent.
 *
 *     - type: jellyfin-latest
 *       service: jellyfin
 *       user: tom        # les bibliothèques visibles par cet utilisateur
 *       span: 2
 *       rows: 2
 *
 * L'API est appelée sur `check` du service (adresse interne), les affiches et
 * les liens pointent sur `url` (adresse ouverte par le navigateur).
 */
#[AsCard('jellyfin-latest')]
final readonly class JellyfinLatestCard implements CardTypeInterface, CardFeedsInterface
{
    private const TYPES = ['Movie', 'Episode', 'Series', 'MusicAlbum', 'Audio', 'Book'];

    public function __construct(
        private JellyfinClient $jellyfin,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired(['service', 'user'])
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setInfo('service', 'service du catalogue, avec une url')
            ->setAllowedTypes('user', 'string')
            ->setInfo('user', 'nom d\'utilisateur Jellyfin')
            ->setDefault('limit', 12)
            ->setAllowedTypes('limit', 'int')
            ->setAllowedValues('limit', static fn (int $n): bool => $n >= 1 && $n <= 30)
            ->setDefault('types', ['Movie', 'Episode'])
            ->setAllowedTypes('types', 'string[]')
            ->setAllowedValues('types', static fn (array $types): bool => [] !== $types && [] === array_diff($types, self::TYPES))
            ->setDefault('now_playing', true)
            ->setAllowedTypes('now_playing', 'bool')
            ->setInfo('now_playing', 'afficher les lectures en cours')
            ->setDefault('now_playing_refresh', 10)
            ->setAllowedTypes('now_playing_refresh', 'int')
            ->setAllowedValues('now_playing_refresh', static fn (int $s): bool => $s >= 5)
            ->setInfo('now_playing_refresh', 'secondes entre deux lectures des lectures en cours');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $this->service($card, $dashboard);

        return [
            'title' => $card->title ?? 'Derniers ajouts',
            'service' => $service->name,
            'url' => $service->url,
            'icon' => $service->icon,
        ];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $this->service($card, $dashboard);
        $api = $service->check ?? (string) $service->url;
        $public = rtrim((string) $service->url, '/');

        $userId = $this->jellyfin->userId($api, $card->options['user']);
        $items = $this->jellyfin->latest($api, $userId, $card->options['limit'], $card->options['types']);

        return ['items' => array_map(static fn (JellyfinItem $i): array => [
            ...self::itemData($i, $public),
            'addedAt' => $i->addedAt?->format(\DATE_ATOM),
        ], $items)];
    }

    public function feeds(CardInstance $card): array
    {
        return $card->options['now_playing'] ? ['sessions' => $card->options['now_playing_refresh']] : [];
    }

    public function fetchFeed(string $feed, CardInstance $card, Dashboard $dashboard): array
    {
        $service = $this->service($card, $dashboard);
        $public = rtrim((string) $service->url, '/');
        $sessions = $this->jellyfin->sessions($service->check ?? (string) $service->url);

        // Lectures actives d'abord, puis par utilisateur.
        usort($sessions, static fn (JellyfinSession $a, JellyfinSession $b): int => [$a->paused, $a->user] <=> [$b->paused, $b->user]);

        return ['sessions' => array_map(static fn (JellyfinSession $s): array => [
            'id' => $s->id,
            'user' => $s->user,
            'device' => $s->device,
            'client' => $s->client,
            ...array_diff_key(self::itemData($s->item, $public), ['id' => true]),
            'position' => $s->position,
            'duration' => $s->duration,
            'paused' => $s->paused,
            'transcoding' => $s->transcoding,
        ], $sessions)];
    }

    /**
     * @return array{id: string, title: string, subtitle: ?string, image: ?string, link: string}
     */
    private static function itemData(JellyfinItem $i, string $public): array
    {
        return [
            'id' => $i->id,
            'title' => $i->seriesName ?? $i->name,
            'subtitle' => self::subtitle($i),
            'image' => null !== $i->imageTag
                ? \sprintf('%s/Items/%s/Images/Primary?fillHeight=360&quality=90&tag=%s', $public, $i->imageItemId, rawurlencode($i->imageTag))
                : null,
            'link' => \sprintf('%s/web/#/details?id=%s&serverId=%s', $public, $i->id, $i->serverId),
        ];
    }

    private static function subtitle(JellyfinItem $item): ?string
    {
        if (null !== $item->seriesName) {
            $code = null !== $item->season && null !== $item->episode ? \sprintf('S%02dE%02d · ', $item->season, $item->episode) : '';

            return $code.$item->name;
        }

        return null !== $item->year ? (string) $item->year : null;
    }

    private function service(CardInstance $card, Dashboard $dashboard): ServiceDefinition
    {
        return $dashboard->services[$card->options['service']];
    }
}
