<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardFeedsInterface;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\MusicAssistant\MusicAssistantClient;
use App\Source\MusicAssistant\MusicItem;
use App\Source\MusicAssistant\Speaker;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Music Assistant : ce que jouent les enceintes (flux `speakers`, toutes les
 * 10 s) et les dernières écoutes en slider. Pochettes publiques (imageproxy).
 *
 *     - type: music-assistant
 *       service: music
 *       speakers: [Chambre Nina]   # sinon toutes les enceintes
 *       span: 2
 *       rows: 2
 */
#[AsCard('music-assistant')]
final readonly class MusicAssistantCard implements CardTypeInterface, CardFeedsInterface
{
    public function __construct(
        private MusicAssistantClient $music,
        /** Instant présent (horodatage Unix), pour les tests ; sinon maintenant */
        private ?float $now = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setDefault('limit', 12)
            ->setAllowedTypes('limit', 'int')
            ->setAllowedValues('limit', static fn (int $n): bool => $n >= 1 && $n <= 50)
            ->setDefault('speakers', [])
            ->setAllowedTypes('speakers', 'string[]')
            ->setInfo('speakers', 'noms des enceintes à montrer (tels que dans Music Assistant) ; vide = toutes')
            ->setDefault('speakers_refresh', 10)
            ->setAllowedTypes('speakers_refresh', 'int')
            ->setAllowedValues('speakers_refresh', static fn (int $s): bool => $s >= 5)
            ->setInfo('speakers_refresh', 'secondes entre deux lectures de l\'état des enceintes');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return ['title' => $card->title ?? $service->name, 'service' => $service->name, 'url' => $service->url, 'icon' => $service->icon];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        [$api, $public] = $this->addresses($card, $dashboard);

        return ['recent' => array_map(static fn (MusicItem $i): array => [
            'name' => $i->name,
            'type' => $i->type,
            'image' => null === $i->image ? null : $public.$i->image,
        ], $this->music->recentlyPlayed($api, $card->options['limit']))];
    }

    public function feeds(CardInstance $card): array
    {
        return ['speakers' => $card->options['speakers_refresh']];
    }

    public function fetchFeed(string $feed, CardInstance $card, Dashboard $dashboard): array
    {
        [$api, $public] = $this->addresses($card, $dashboard);
        $now = $this->now ?? microtime(true);
        $only = $card->options['speakers'];
        $speakers = array_filter($this->music->speakers($api), static fn (Speaker $s): bool => [] === $only || \in_array($s->name, $only, true));

        return ['speakers' => array_values(array_map(static function (Speaker $s) use ($public, $now): array {
            $speaker = ['id' => $s->id, 'name' => $s->name, 'state' => $s->state];
            if (null === $m = $s->playing) {
                return $speaker;
            }
            // Position au moment de la réponse : le front l'avance ensuite seul.
            $elapsed = $m->elapsed + ('playing' === $s->state ? max(0, $now - $m->elapsedAt) : 0);

            return $speaker + [
                'title' => $m->title,
                'artist' => $m->artist,
                'album' => $m->album,
                'image' => null === $m->image ? null : $public.$m->image,
                'duration' => $m->duration,
                'elapsed' => (int) round(null === $m->duration ? $elapsed : min($elapsed, $m->duration)),
                'accent' => $m->accent,
            ];
        }, $speakers))];
    }

    /**
     * @return array{string, string} adresse de l'API, adresse publique (navigateur)
     */
    private function addresses(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return [$service->check ?? (string) $service->url, rtrim((string) $service->url, '/')];
    }
}
