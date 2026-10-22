<?php

namespace App\Card\Type;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Source\C411\C411Client;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Profil C411 : niveau et XP, classe (emblème, quêtes, voies), Zen, volumes
 * envoyés et reçus, état du téléchargement.
 *
 *     - type: c411
 *       service: c411
 *       span: 2
 *       rows: 2
 *       refresh: 60
 */
#[AsCard('c411')]
final readonly class C411Card implements CardTypeInterface
{
    /** Ordre d'affichage des voies, fixe. */
    private const VOIES = ['seed', 'upload', 'specialization', 'competitivity'];

    public function __construct(
        private C411Client $c411,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $withUrl = array_keys(array_filter($dashboard->services, static fn (ServiceDefinition $s): bool => null !== $s->url));

        $resolver
            ->setRequired('service')
            ->setAllowedValues('service', array_map('strval', $withUrl));
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];

        return [
            'title' => $card->title ?? $service->name,
            'service' => $service->name,
            'url' => $service->url,
            'icon' => $service->icon,
        ];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        $service = $dashboard->services[$card->options['service']];
        $base = rtrim((string) $service->url, '/');
        $p = $this->c411->profile($service->check ?? $base);

        $level = $p['level'];
        $span = $level['xpIntoLevel'] + $level['xpToNextLevel'];
        $up = (int) $p['transfer']['uploaded']['total'];
        $down = (int) $p['transfer']['downloaded']['total'];

        return [
            'user' => [
                'name' => $p['username'],
                'avatar' => null !== $p['avatarUrl'] ? (str_starts_with($p['avatarUrl'], '/') ? $base.$p['avatarUrl'] : $p['avatarUrl']) : null,
                'memberSince' => isset($p['memberSince']) ? (new \DateTimeImmutable($p['memberSince']))->format(\DATE_ATOM) : null,
            ],
            'level' => [
                'level' => $level['level'],
                'xp' => $level['xp'],
                'xpIntoLevel' => $level['xpIntoLevel'],
                'xpToNextLevel' => $level['xpToNextLevel'],
                'progress' => $span > 0 ? round($level['xpIntoLevel'] / $span, 4) : 0,
                // Badge du site : emblème bleu en fond, cadran silver devant, niveau
                // par-dessus. Existent aussi embleme-gold et cadran-bronze / -gold.
                'badge' => [
                    'emblem' => $base.'/images/ic-grade/embleme-blue.webp',
                    'dial' => $base.'/images/ic-grade/cadran-silver.webp',
                ],
            ],
            'class' => null !== $p['class'] ? self::classData($p['class']) : null,
            'team' => null !== $p['team'] ? ['name' => $p['team']['name'], 'tag' => $p['team']['tag']] : null,
            'zen' => ['balance' => $p['zen']['balance'], 'earned' => $p['zen']['totalEarned'], 'spent' => $p['zen']['totalSpent']],
            'transfer' => ['uploaded' => $up, 'downloaded' => $down, 'ratio' => $down > 0 ? round($up / $down, 2) : null],
            'download' => ['allowed' => (bool) $p['download']['allowed'], 'blockedReason' => $p['download']['blockedReason']],
            'freeleech' => ['active' => (bool) $p['freeleech']['active'], 'sources' => $p['freeleech']['sources']],
        ];
    }

    /**
     * @param array<string, mixed> $class
     *
     * @return array<string, mixed>
     */
    private static function classData(array $class): array
    {
        $progress = $class['progress'];

        return [
            // La classe effective tient compte de l'arbre (« resident » …).
            'slug' => $class['effectiveSlug'] ?? $class['slug'],
            'grade' => ['tier' => $progress['grade']['tier'], 'level' => $progress['grade']['level']],
            'stars' => $progress['stars'],
            'questsCompleted' => $progress['questsCompleted'],
            'questsTotal' => $progress['questsTotal'],
            'bonusPercent' => $class['treeBonusBp'] / 100,
            'voies' => array_map(static fn (string $key): array => [
                'key' => $key,
                'star' => (bool) ($class['voies'][$key]['star'] ?? false),
                'completed' => $class['voies'][$key]['questsCompleted'] ?? 0,
                'total' => $class['voies'][$key]['questsTotal'] ?? 0,
            ], self::VOIES),
        ];
    }
}
