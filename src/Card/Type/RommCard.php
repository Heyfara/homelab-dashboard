<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Romm\RommClient;
use App\Source\Romm\RommGame;
use App\Source\Romm\RommPlatform;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Bibliothèque de jeux RomM : derniers jeux ajoutés avec leur jaquette, et
 * nombre de jeux par plateforme.
 *
 *     - type: romm
 *       service: romm
 *       platforms: [acpc, amstrad-gx4000]   # facultatif : ces plateformes seulement
 *       span: 2
 *       rows: 2
 *
 * L'API est appelée sur `check` du service, les jaquettes (publiques) et les
 * liens pointent sur `url`.
 */
#[AsCard('romm')]
final readonly class RommCard implements CardTypeInterface
{
    public function __construct(
        private RommClient $romm,
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
            ->setDefault('platforms', [])
            ->setAllowedTypes('platforms', 'string[]')
            ->setInfo('platforms', 'identifiants (slug) des plateformes à montrer, vide = toutes');
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
        $only = $card->options['platforms'];

        $platforms = array_filter(
            $this->romm->platforms($api),
            static fn (RommPlatform $p): bool => $p->games > 0 && ([] === $only || \in_array($p->slug, $only, true)),
        );
        usort($platforms, static fn (RommPlatform $a, RommPlatform $b): int => $b->games <=> $a->games);
        $stats = $this->romm->stats($api);

        // Filtre sans plateforme correspondante : rien, plutôt que tous les jeux.
        $games = [] !== $only && [] === $platforms
            ? []
            : $this->romm->recent($api, $card->options['limit'], [] === $only ? [] : array_map(static fn (RommPlatform $p): int => $p->id, $platforms));

        return [
            'stats' => [
                'games' => array_sum(array_map(static fn (RommPlatform $p): int => $p->games, $platforms)),
                'platforms' => \count($platforms),
                'saves' => $stats['saves'] + $stats['states'],
            ],
            'games' => array_map(static fn (RommGame $g): array => [
                'id' => $g->id,
                'title' => $g->name,
                'platform' => $g->platform,
                'cover' => null !== $g->cover ? $public.$g->cover : null,
                'link' => \sprintf('%s/rom/%d', $public, $g->id),
                'addedAt' => $g->addedAt?->format(\DATE_ATOM),
            ], $games),
            'platforms' => array_map(static fn (RommPlatform $p): array => [
                'name' => $p->name,
                'games' => $p->games,
                'link' => \sprintf('%s/platform/%d', $public, $p->id),
            ], $platforms),
        ];
    }
}
