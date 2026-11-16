<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardActionRefusedException;
use App\Card\CardActionsInterface;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\Dagu\DaguClient;
use App\Source\Dagu\DaguRunner;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Déclaration de chiffre d'affaires auto-entrepreneur sur le site de l'Urssaf.
 * Le bouton lance une tâche Dagu (rôle Ansible urssaf-declaration) qui ouvre
 * un navigateur sur helium, se connecte et remplit le montant ; la personne
 * vérifie et clique elle-même sur « Valider » dans l'écran (noVNC) affiché
 * par la carte. Le dashboard ne valide jamais rien.
 *
 * Paramètres de la tâche : MONTANT, TEST, VNC_PASSWORD (tiré à chaque
 * lancement, relu dans les paramètres du passage pour afficher l'écran).
 * L'étape en cours vient des lignes « STATUS <étape> <message> » du journal.
 *
 *     - type: urssaf
 *       service: dagu
 *       task: urssaf-declaration
 *       screen: https://urssaf.example
 *       deadlines:
 *         - { period: 4e trimestre 2026, opens: 2027-01-01, due: 2027-01-31 }
 *       refresh: 5
 *       span: 2
 *       rows: 2
 */
#[AsCard('urssaf')]
final readonly class UrssafCard implements CardTypeInterface, CardActionsInterface
{
    private const MAX_AMOUNT = 10_000_000;

    public function __construct(private DaguClient $dagu, private DaguRunner $runner)
    {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_map('strval', array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url)));

        $resolver
            ->setRequired(['service', 'screen'])
            ->setAllowedValues('service', $withUrl)
            ->setInfo('service', 'service Dagu du catalogue (`check` sert d\'adresse d\'API s\'il est donné)')
            ->setDefault('task', 'urssaf-declaration')
            ->setAllowedTypes('task', 'string')
            ->setInfo('task', 'tâche Dagu lancée par le bouton')
            ->setAllowedTypes('screen', 'string')
            ->setInfo('screen', 'adresse de l\'écran noVNC de la session (https://urssaf.home)')
            ->setDefault('test', false)
            ->setAllowedTypes('test', 'bool')
            ->setInfo('test', 'lancer en mode test : l\'envoi de la déclaration et le paiement sont bloqués')
            ->setDefault('deadlines', [])
            ->setAllowedTypes('deadlines', 'array[]')
            ->setInfo('deadlines', 'calendrier des déclarations : liste de {period, opens, due} (dates AAAA-MM-JJ)')
            ->setNormalizer('deadlines', static function (Options $options, array $deadlines): array {
                $normalized = [];
                foreach ($deadlines as $d) {
                    $opens = self::date($d['opens'] ?? null);
                    $due = self::date($d['due'] ?? null);
                    if (!\is_string($d['period'] ?? null) || null === $opens || null === $due) {
                        throw new InvalidOptionsException('Chaque échéance demande period (texte), opens et due (dates AAAA-MM-JJ).');
                    }
                    $normalized[] = ['period' => $d['period'], 'opens' => $opens, 'due' => $due];
                }

                return $normalized;
            });
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return [
            'title' => $card->title ?? 'Déclaration Urssaf',
            'taskUrl' => rtrim((string) $dashboard->services[$card->options['service']]->url, '/').'/dags/'.rawurlencode($card->options['task']),
            'test' => $card->options['test'],
        ];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $api = $this->api($card, $dashboard);
        $task = $card->options['task'];
        $run = $this->dagu->latestRun($api, $task);

        $view = null;
        if (null !== $run) {
            [$step, $message] = self::lastStatus($this->dagu->stepLog($api, $task, $run->id, $task, 200));
            $amount = $run->params['MONTANT'] ?? '';
            $password = $run->params['VNC_PASSWORD'] ?? '';
            $view = [
                'id' => $run->id,
                'state' => $run->state,
                'startedAt' => $run->startedAt->format(\DATE_ATOM),
                'finishedAt' => $run->finishedAt?->format(\DATE_ATOM),
                'amount' => ctype_digit($amount) ? (int) $amount : null,
                'test' => 'true' === ($run->params['TEST'] ?? ''),
                'step' => $step,
                'message' => $message,
                // Mot de passe à usage unique de la session, valable tant qu'elle tourne
                'screenUrl' => 'running' === $run->state && '' !== $password
                    ? rtrim($card->options['screen'], '/').'/vnc.html?'.http_build_query(['autoconnect' => 1, 'resize' => 'scale', 'reconnect' => 1, 'password' => $password])
                    : null,
            ];
        }

        return [
            'run' => $view,
            'deadline' => self::nextDeadline($card->options['deadlines'], new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'))),
        ];
    }

    public function actions(CardInstance $card): array
    {
        return ['start', 'stop'];
    }

    public function runAction(string $action, array $payload, CardInstance $card, Dashboard $dashboard): array
    {
        $api = $this->api($card, $dashboard);
        $task = $card->options['task'];

        if ('stop' === $action) {
            $run = $this->dagu->latestRun($api, $task);
            if (null === $run || !\in_array($run->state, ['running', 'queued'], true)) {
                throw new CardActionRefusedException('Aucune session en cours.');
            }
            $this->runner->stop($api, $task, $run->id);

            return ['stopped' => $run->id];
        }

        $amount = $payload['amount'] ?? null;
        if (!\is_int($amount) || $amount < 0 || $amount > self::MAX_AMOUNT) {
            throw new CardActionRefusedException('Montant attendu : un nombre entier d\'euros, 0 ou plus.');
        }

        return ['runId' => $this->runner->start($api, $task, [
            'MONTANT' => (string) $amount,
            'TEST' => $card->options['test'] ? 'true' : 'false',
            'VNC_PASSWORD' => bin2hex(random_bytes(4)),
        ])];
    }

    /**
     * Échéance en cours ou à venir : la première dont la date limite n'est pas passée.
     *
     * @param list<array{period: string, opens: string, due: string}> $deadlines
     *
     * @return array{period: string, opens: string, due: string, open: bool}|null
     */
    public static function nextDeadline(array $deadlines, \DateTimeImmutable $today): ?array
    {
        $day = $today->format('Y-m-d');
        usort($deadlines, static fn (array $a, array $b): int => $a['due'] <=> $b['due']);
        foreach ($deadlines as $d) {
            if ($d['due'] >= $day) {
                return ['period' => $d['period'], 'opens' => $d['opens'], 'due' => $d['due'], 'open' => $d['opens'] <= $day];
            }
        }

        return null;
    }

    /**
     * Dernière ligne « STATUS <étape> <message> » du journal.
     *
     * @return array{?string, ?string}
     */
    private static function lastStatus(string $log): array
    {
        if (preg_match_all('/^STATUS (\S+) (.*)$/m', $log, $m, \PREG_SET_ORDER) < 1) {
            return [null, null];
        }
        $last = end($m);

        return [$last[1], trim($last[2])];
    }

    private function api(CardInstance $card, Dashboard $dashboard): string
    {
        $service = $dashboard->services[$card->options['service']];

        return $service->check ?? (string) $service->url;
    }

    /**
     * Date « AAAA-MM-JJ », quelle que soit la façon dont le YAML l'a lue : texte
     * entre guillemets, horodatage (date sans guillemets) ou objet date.
     */
    private static function date(mixed $value): ?string
    {
        if (\is_int($value)) {
            return gmdate('Y-m-d', $value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (!\is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
