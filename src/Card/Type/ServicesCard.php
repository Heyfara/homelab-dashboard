<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Container\ContainerState;
use App\Source\Container\ContainerStatusSource;
use App\Source\Http\HttpProbe;
use App\Source\SourceUnavailableException;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Lanceur : plusieurs services du catalogue en une grille d'icônes, chacune avec
 * son état (même lecture que la carte `service`). Les containers sont lus une
 * fois par machine, les sondes HTTP partent en parallèle.
 *
 *     - type: services
 *       services: [recettes, photos, films]
 *       span: 2
 *       rows: 2
 */
#[AsCard('services')]
final readonly class ServicesCard implements CardTypeInterface
{
    public function __construct(
        private ContainerStatusSource $containers,
        private HttpProbe $probe,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $known = array_map('strval', array_keys($dashboard->services));

        $resolver
            ->setRequired('services')
            ->setAllowedTypes('services', 'string[]')
            ->setAllowedValues('services', static function (array $ids) use ($known): bool {
                if ([] === $ids) {
                    throw new InvalidOptionsException('L\'option « services » doit lister au moins un service.');
                }
                $unknown = array_diff($ids, $known);
                if ([] !== $unknown) {
                    throw new InvalidOptionsException(\sprintf('Service inconnu dans « services » : %s (voir la liste services:).', implode(', ', $unknown)));
                }

                return true;
            });
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return [
            'services' => array_map(static fn (ServiceDefinition $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'url' => $s->url,
                'icon' => $s->icon,
            ], $this->services($card, $dashboard)),
        ];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $services = $this->services($card, $dashboard);

        $urls = [];
        foreach ($services as $s) {
            $url = $s->check ?? $s->url;
            if (null !== $url) {
                $urls[$s->id] = $url;
            }
        }
        $probes = $this->probe->probeAll($urls);

        /** @var array<string, array<string, ContainerState>|string> $byHost états par machine, ou message d'erreur */
        $byHost = [];
        $status = [];
        foreach ($services as $s) {
            $host = null !== $s->host ? ($dashboard->hosts[$s->host] ?? null) : null;
            $container = null;
            if (null !== $s->container && null !== $host?->beszelSystem) {
                if (!isset($byHost[$host->name])) {
                    try {
                        $byHost[$host->name] = $this->containers->all($host);
                    } catch (SourceUnavailableException $e) {
                        // Source en panne : ne doit pas masquer la réponse HTTP des services.
                        $byHost[$host->name] = $e->getMessage();
                    }
                }
                $states = $byHost[$host->name];
                $container = \is_string($states) ? ['error' => $states] : ($states[$s->container] ?? ContainerState::stopped())->toArray();
            }

            $status[$s->id] = [
                'container' => $container,
                'probe' => isset($probes[$s->id]) ? $probes[$s->id]->toArray() : null,
            ];
        }

        return ['status' => $status];
    }

    /**
     * @return list<ServiceDefinition>
     */
    private function services(CardInstance $card, Dashboard $dashboard): array
    {
        return array_map(static fn (string $id): ServiceDefinition => $dashboard->services[$id], $card->options['services']);
    }
}
