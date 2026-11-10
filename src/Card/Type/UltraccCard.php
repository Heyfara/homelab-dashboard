<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Ultracc\UltraccClient;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Abonnement Ultra.cc : stockage utilisé et trafic du mois, avec une
 * projection au rythme actuel jusqu'à la prochaine remise à zéro (au-delà du
 * quota, Ultra.cc bride le débit).
 *
 *     - type: ultracc
 *       service: seedbox        # service avec une url sur l'hôte Ultra.cc
 *       traffic_quota: 2000     # facultatif : quota de trafic en Go, pour afficher le volume
 *       span: 2
 *       refresh: 900
 */
#[AsCard('ultracc')]
final readonly class UltraccCard implements CardTypeInterface
{
    /** Recul minimal (secondes) depuis la remise à zéro pour extrapoler le trafic. */
    private const MIN_PROJECTION_ELAPSED = 86400;

    public function __construct(
        private UltraccClient $ultracc,
        private ?string $now = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setInfo('service', 'service dont l\'url est sur l\'hôte Ultra.cc (seuls schéma et hôte comptent)')
            ->setDefault('traffic_quota', null)
            ->setAllowedTypes('traffic_quota', ['null', 'int'])
            ->setAllowedValues('traffic_quota', static fn (?int $q): bool => null === $q || $q > 0)
            ->setInfo('traffic_quota', 'quota de trafic mensuel en Go (l\'API ne donne qu\'un pourcentage)');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return ['title' => $card->title ?? $service->name, 'url' => $service->url, 'icon' => $service->icon];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $stats = $this->ultracc->stats((string) $dashboard->services[$card->options['service']]->url);
        $quota = $card->options['traffic_quota'];

        $now = new \DateTimeImmutable($this->now ?? 'now');
        $elapsed = $now->getTimestamp() - $stats->lastTrafficReset->getTimestamp();
        $period = $stats->nextTrafficReset->getTimestamp() - $stats->lastTrafficReset->getTimestamp();
        $projected = $elapsed >= self::MIN_PROJECTION_ELAPSED && $period > 0
            ? round($stats->trafficUsedPercent * $period / $elapsed, 2)
            : null;

        return [
            'storage' => [
                'used' => $stats->usedGiB,
                'total' => $stats->totalGiB,
                'free' => $stats->freeGiB,
                'percent' => $stats->totalGiB > 0 ? round($stats->usedGiB / $stats->totalGiB * 100, 2) : null,
            ],
            'traffic' => [
                'percent' => $stats->trafficUsedPercent,
                'used' => null !== $quota ? round($stats->trafficUsedPercent * $quota / 100, 1) : null,
                'quota' => $quota,
                'lastReset' => $stats->lastTrafficReset->format(\DATE_ATOM),
                'nextReset' => $stats->nextTrafficReset->format(\DATE_ATOM),
                'projectedPercent' => $projected,
            ],
        ];
    }
}
