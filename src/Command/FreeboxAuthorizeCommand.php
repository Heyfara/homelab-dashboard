<?php

namespace App\Command;

use App\Source\Freebox\FreeboxClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Obtenir le jeton d'application Freebox (FREEBOX_APP_TOKEN), une seule fois,
 * depuis le réseau de la maison : la demande se valide sur l'écran de la box.
 */
#[AsCommand('dashboard:freebox-authorize', "Demande un jeton d'application à la Freebox (à valider sur la box)")]
final readonly class FreeboxAuthorizeCommand
{
    private \Closure $wait;

    public function __construct(
        private FreeboxClient $freebox,
        /** Attente entre deux interrogations, remplacée dans les tests */
        ?\Closure $wait = null,
    ) {
        $this->wait = $wait ?? static fn () => sleep(2);
    }

    public function __invoke(OutputInterface $output): int
    {
        $request = $this->freebox->requestAuthorization('dashboard');
        $output->writeln("Accepter « Homelab Dashboard » sur l'écran de la Freebox (flèche droite, puis OK)…");

        while ('pending' === $status = $this->freebox->authorizationStatus($request['track'])) {
            ($this->wait)();
        }
        if ('granted' !== $status) {
            $output->writeln(\sprintf('Demande non acceptée : %s', $status));

            return Command::FAILURE;
        }

        $output->writeln('Accepté. À mettre dans le vault Ansible (jamais dans le dépôt) :');
        $output->writeln('FREEBOX_APP_TOKEN='.$request['token']);
        $output->writeln("Dans Freebox OS (Paramètres → Gestion des accès → Applications), décocher toutes ses permissions : la lecture n'en demande aucune.");

        return Command::SUCCESS;
    }
}
