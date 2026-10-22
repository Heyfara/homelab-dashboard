<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Crafty\CraftyClient;
use App\Source\Crafty\CraftyServer;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Serveurs Minecraft de Crafty Controller : état, joueurs connectés, version.
 *
 *     - type: crafty
 *       service: crafty
 *       servers: [Monde survie, Monde créatif]   # sinon tous
 *       public: [Public]                                  # serveurs ouverts sur Internet
 *       span: 2
 *       rows: 2
 */
#[AsCard('crafty')]
final readonly class CraftyCard implements CardTypeInterface
{
    private const ORDER = ['online' => 0, 'starting' => 1, 'crashed' => 2, 'stopped' => 3];

    public function __construct(
        private CraftyClient $crafty,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setDefault('servers', [])
            ->setAllowedTypes('servers', 'string[]')
            ->setInfo('servers', 'noms des serveurs à montrer, dans cet ordre ; vide = tous, en ligne d\'abord')
            ->setDefault('public', [])
            ->setAllowedTypes('public', 'string[]')
            ->setInfo('public', 'noms des serveurs ouverts sur Internet, signalés sur la carte');
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
        $chosen = $card->options['servers'];
        $servers = $this->crafty->servers($service->check ?? (string) $service->url);

        if ([] !== $chosen) {
            $servers = array_values(array_filter($servers, static fn (CraftyServer $s): bool => \in_array($s->name, $chosen, true)));
            usort($servers, static fn (CraftyServer $a, CraftyServer $b): int => array_search($a->name, $chosen, true) <=> array_search($b->name, $chosen, true));
        } else {
            usort($servers, static fn (CraftyServer $a, CraftyServer $b): int => [self::ORDER[$a->state] ?? 9, mb_strtolower($a->name)] <=> [self::ORDER[$b->state] ?? 9, mb_strtolower($b->name)]);
        }

        return [
            'playersOnline' => array_sum(array_map(static fn (CraftyServer $s): int => 'online' === $s->state ? $s->online : 0, $servers)),
            'servers' => array_map(static fn (CraftyServer $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'state' => $s->state,
                'online' => $s->online,
                'max' => $s->max,
                'players' => $s->players,
                'version' => $s->version,
                'motd' => $s->motd,
                'worldSize' => $s->worldSize,
                'startedAt' => $s->startedAt?->format(\DATE_ATOM),
                'public' => \in_array($s->name, $card->options['public'], true),
                'link' => \sprintf('%s/panel/server_detail?id=%s', $public, rawurlencode($s->id)),
                'icon' => $s->icon,
            ], $servers),
        ];
    }
}
