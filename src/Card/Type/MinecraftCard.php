<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Crafty\CraftyClient;
use App\Source\Crafty\CraftyServer;
use App\Source\Http\RemoteFile;
use App\Source\SourceUnavailableException;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un serveur Minecraft en image : rendu du monde (fait à part, par exemple
 * avec mcmap) et, par-dessus, l'état du serveur et ses joueurs via Crafty.
 *
 *     - type: minecraft
 *       service: crafty
 *       server: Monde survie
 *       image: https://artefacts.example/minecraft/monde-principal.png
 *       span: 2
 *       rows: 3
 */
#[AsCard('minecraft')]
final readonly class MinecraftCard implements CardTypeInterface
{
    public function __construct(
        private CraftyClient $crafty,
        private RemoteFile $files,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired(['service', 'server', 'image'])
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setAllowedTypes('server', 'string')
            ->setInfo('server', 'nom du serveur dans Crafty')
            ->setAllowedTypes('image', 'string')
            ->setInfo('image', 'adresse du rendu du monde (PNG), lisible par le navigateur');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return ['title' => $card->title ?? $card->options['server'], 'icon' => $dashboard->services[$card->options['service']]->icon];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];
        $servers = $this->crafty->servers($service->check ?? (string) $service->url);
        $server = array_values(array_filter($servers, static fn (CraftyServer $s): bool => $s->name === $card->options['server']))[0]
            ?? throw new SourceUnavailableException(\sprintf('Serveur Crafty inconnu « %s » (serveurs : %s)', $card->options['server'], implode(', ', array_map(static fn (CraftyServer $s): string => $s->name, $servers))));
        $url = $card->options['image'];
        $renderedAt = $this->files->lastModified($url);

        return [
            'server' => [
                'state' => $server->state,
                'online' => $server->online,
                'max' => $server->max,
                'players' => $server->players,
                'version' => $server->version,
                'link' => \sprintf('%s/panel/server_detail?id=%s', rtrim((string) $service->url, '/'), rawurlencode($server->id)),
            ],
            'image' => [
                // ?v= : un nouveau rendu change l'adresse, le navigateur ne garde pas l'ancienne image.
                'url' => null === $renderedAt ? $url : $url.(str_contains($url, '?') ? '&' : '?').'v='.$renderedAt->getTimestamp(),
                'renderedAt' => $renderedAt?->format(\DATE_ATOM),
            ],
        ];
    }
}
