<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Jelu\JeluClient;
use App\Source\Jelu\JeluReading;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Carnet de lecture Jelu de la famille : qui lit quoi en ce moment, livres
 * terminés dans l'année (par lecteur) et leurs couvertures en slider.
 *
 *     - type: jelu
 *       service: jelu
 *       readers: { tom: Tom, lea: Lea }   # sinon tous, par nom de connexion
 *       span: 2
 *       rows: 2
 */
#[AsCard('jelu')]
final readonly class JeluCard implements CardTypeInterface
{
    public function __construct(
        private JeluClient $jelu,
        /** Date du jour (AAAA-MM-JJ), pour les tests ; sinon aujourd'hui à Paris */
        private ?string $today = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl))
            ->setDefault('readers', [])
            ->setAllowedTypes('readers', 'string[]')
            ->setInfo('readers', 'nom de connexion Jelu → prénom affiché ; limite la carte à ces lecteurs (vide = tous)')
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
        // Noms de connexion comparés sans la casse : « Lea » dans Jelu, « lea » dans le YAML.
        $readers = [];
        foreach ($card->options['readers'] as $login => $display) {
            $readers[mb_strtolower((string) $login)] = $display;
        }
        $year = new \DateTimeImmutable($this->today ?? 'today', new \DateTimeZone('Europe/Paris'));
        $year = $year->setDate((int) $year->format('Y'), 1, 1);

        $mine = static fn (JeluReading $r): bool => [] === $readers || isset($readers[mb_strtolower($r->login)]);
        $name = static fn (string $login): string => $readers[mb_strtolower($login)] ?? ucfirst($login);
        $view = static fn (JeluReading $r): array => [
            'reader' => $name($r->login),
            'bookId' => $r->bookId,
            'title' => $r->title,
            'authors' => $r->authors,
            'cover' => null === $r->image ? null : \sprintf('%s/files/%s', $public, rawurlencode($r->image)),
            'link' => \sprintf('%s/books/%s', $public, rawurlencode($r->bookId)),
            'percent' => $r->percentRead,
            'date' => $r->date?->format(\DATE_ATOM),
        ];

        $reading = array_values(array_filter($this->jelu->currentlyReading($api), $mine));
        $finished = array_values(array_filter($this->jelu->finishedSince($api, $year), $mine));

        // Lecteurs listés dans l'ordre de l'option (même à 0), sinon ceux qui apparaissent.
        $counts = array_fill_keys([] !== $readers ? array_map('strval', array_keys($readers)) : [], 0);
        foreach ($finished as $r) {
            $counts[mb_strtolower($r->login)] = ($counts[mb_strtolower($r->login)] ?? 0) + 1;
        }
        if ([] === $readers) {
            foreach ($reading as $r) {
                $counts[mb_strtolower($r->login)] ??= 0;
            }
            ksort($counts);
        }

        return [
            'year' => (int) $year->format('Y'),
            // Clé entière si le nom de connexion est un nombre : on la repasse en chaîne.
            'readers' => array_map(static fn (int|string $login, int $n): array => ['name' => $name((string) $login), 'finished' => $n], array_keys($counts), $counts),
            'reading' => array_map($view, $reading),
            'finished' => array_map($view, \array_slice($finished, 0, $card->options['limit'])),
        ];
    }
}
