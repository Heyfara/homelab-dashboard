<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Dagu\DaguClient;
use App\Source\Dagu\DaguRun;
use App\Source\Dagu\DaguTask;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Tâches planifiées du homelab, lues dans Dagu : état et heure du dernier
 * passage, prochain passage, historique. Seules les tâches qui portent le
 * label choisi (`dashboard` par défaut) s'affichent. Chaque tâche ouvre sa
 * page dans Dagu, d'où on peut la relancer.
 *
 *     - type: dagu
 *       service: dagu                      # service Dagu du catalogue
 *       titles: { backup-nuit: Sauvegardes de la nuit }
 *       span: 2
 *       rows: 2
 */
#[AsCard('dagu')]
final readonly class DaguCard implements CardTypeInterface
{
    private const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    public function __construct(private DaguClient $dagu)
    {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_map('strval', array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url)));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', $withUrl)
            ->setInfo('service', 'service Dagu du catalogue (`check` sert d\'adresse d\'API s\'il est donné)')
            ->setDefault('label', 'dashboard')
            ->setAllowedTypes('label', 'string')
            ->setInfo('label', 'seules les tâches qui portent ce label s\'affichent')
            ->setDefault('runs', 10)
            ->setAllowedTypes('runs', 'int')
            ->setInfo('runs', 'nombre de passages lus par tâche (historique)')
            ->setDefault('titles', [])
            ->setAllowedTypes('titles', 'string[]')
            ->setInfo('titles', 'nom de la tâche → nom affiché ; sinon le nom avec une majuscule');
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return ['title' => $card->title ?? 'Tâches', 'url' => (string) $dashboard->services[$card->options['service']]->url];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];
        $ui = rtrim((string) $service->url, '/');
        $tasks = $this->dagu->tasks($service->check ?? $ui, $card->options['label'], $card->options['runs']);

        return ['tasks' => array_map(static fn (DaguTask $t): array => [
            'name' => $t->name,
            'title' => $card->options['titles'][$t->name] ?? mb_ucfirst($t->name),
            'description' => $t->description,
            'schedule' => [] === $t->schedules ? null : implode(', ', array_map(self::schedule(...), $t->schedules)),
            'nextRun' => $t->nextRun?->format(\DATE_ATOM),
            'suspended' => $t->suspended,
            'lastRun' => null === ($last = $t->runs[0] ?? null) ? null : [
                'state' => $last->state,
                'startedAt' => $last->startedAt->format(\DATE_ATOM),
                'finishedAt' => $last->finishedAt?->format(\DATE_ATOM),
                'manual' => $last->manual,
            ],
            'history' => array_reverse(array_map(static fn (DaguRun $r): array => [
                'state' => $r->state,
                'startedAt' => $r->startedAt->format(\DATE_ATOM),
                'manual' => $r->manual,
            ], $t->runs)),
            'url' => $ui.'/dags/'.rawurlencode($t->name),
        ], $tasks)];
    }

    /** Planning cron en clair pour les formes courantes, sinon tel quel. */
    public static function schedule(string $cron): string
    {
        $f = preg_split('/\s+/', trim($cron)) ?: [];
        if (5 === \count($f) && 1 === preg_match('#^\*/(\d+)$#', $f[0], $m) && '* * * *' === implode(' ', \array_slice($f, 1))) {
            return \sprintf('toutes les %d min', $m[1]);
        }
        if (5 === \count($f) && ctype_digit($f[0]) && ctype_digit($f[1]) && '*' === $f[2] && '*' === $f[3]) {
            $at = \sprintf('%d h', $f[1]).((int) $f[0] > 0 ? \sprintf(' %02d', $f[0]) : '');
            if ('*' === $f[4]) {
                return 'chaque jour à '.$at;
            }
            if (1 === preg_match('/^[0-7]$/', $f[4])) {
                return \sprintf('le %s à %s', self::DAYS[(int) $f[4]], $at);
            }
        }

        return 'cron '.$cron;
    }
}
