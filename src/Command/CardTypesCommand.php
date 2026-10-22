<?php

namespace App\Command;

use App\Card\CardTypeRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Un identifiant par ligne : la CI compare cette liste aux clés du registre React.
 */
#[AsCommand('dashboard:card-types', 'Liste les types de carte déclarés côté serveur')]
final readonly class CardTypesCommand
{
    public function __construct(
        private CardTypeRegistry $registry,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $output->writeln($this->registry->types());

        return Command::SUCCESS;
    }
}
