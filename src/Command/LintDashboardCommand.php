<?php

namespace App\Command;

use App\Dashboard\DashboardProvider;
use App\Dashboard\InvalidDashboardException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('dashboard:lint', 'Vérifie un fichier dashboard.yaml (structure, types de carte, options, renvois)')]
final readonly class LintDashboardCommand
{
    public function __construct(
        private DashboardProvider $provider,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Fichier à vérifier (par défaut : DASHBOARD_CONFIG)')]
        ?string $file = null,
    ): int {
        $path = $file ?? $this->provider->configPath();

        try {
            $dashboard = $this->provider->build($path);
        } catch (InvalidDashboardException $e) {
            $io->error(\sprintf('%s : %d erreur(s)', $path, \count($e->errors)));
            $io->listing($e->errors);

            return Command::FAILURE;
        }

        $profiles = \count($dashboard->profiles);
        $cards = iterator_count($dashboard->cards());
        $io->success(\sprintf('Configuration valide : %d profil%s, %d carte%s.', $profiles, $profiles > 1 ? 's' : '', $cards, $cards > 1 ? 's' : ''));

        return Command::SUCCESS;
    }
}
