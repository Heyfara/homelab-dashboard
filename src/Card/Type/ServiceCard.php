<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Container\ContainerStatusSource;
use App\Source\Http\HttpProbe;
use App\Source\SourceUnavailableException;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un service du catalogue : lien, description, état du container et réponse HTTP.
 *
 *     - type: service
 *       service: immich
 */
#[AsCard('service')]
final readonly class ServiceCard implements CardTypeInterface
{
    public function __construct(
        private ContainerStatusSource $containers,
        private HttpProbe $probe,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', array_keys($dashboard->services)));
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $this->service($card, $dashboard);

        return [
            'name' => $service->name,
            'description' => $service->description,
            'url' => $service->url,
            'icon' => $service->icon,
            'host' => $service->host,
        ];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $this->service($card, $dashboard);
        $probeUrl = $service->check ?? $service->url;

        return [
            'container' => $this->container($service, $dashboard),
            'probe' => null !== $probeUrl ? $this->probe->probe($probeUrl)->toArray() : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function container(ServiceDefinition $service, Dashboard $dashboard): ?array
    {
        $host = null !== $service->host ? ($dashboard->hosts[$service->host] ?? null) : null;
        if (null === $service->container || null === $host?->beszelSystem) {
            return null;
        }

        try {
            return $this->containers->status($host, $service->container)->toArray();
        } catch (SourceUnavailableException $e) {
            // Source en panne : ne doit pas masquer la réponse HTTP du service.
            return ['error' => $e->getMessage()];
        }
    }

    private function service(CardInstance $card, Dashboard $dashboard): ServiceDefinition
    {
        return $dashboard->services[$card->options['service']];
    }
}
