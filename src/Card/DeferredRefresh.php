<?php

namespace App\Card;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Travaux lancés une fois la réponse envoyée (kernel.terminate : sous PHP-FPM,
 * le client a déjà reçu sa réponse). Sert à relire les sources des cartes
 * périmées sans faire attendre le navigateur.
 */
#[AsEventListener(KernelEvents::TERMINATE)]
final class DeferredRefresh
{
    /** @var array<string, \Closure(): void> */
    private array $jobs = [];

    /** Un seul travail par clé et par requête : le dernier demandé gagne. */
    public function defer(string $key, \Closure $job): void
    {
        $this->jobs[$key] = $job;
    }

    public function run(): void
    {
        $jobs = $this->jobs;
        $this->jobs = [];
        foreach ($jobs as $job) {
            $job();
        }
    }

    public function __invoke(): void
    {
        $this->run();
    }
}
