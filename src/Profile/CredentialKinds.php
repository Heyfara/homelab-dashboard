<?php

namespace App\Profile;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tous les services dont on peut saisir sa clé dans « Mes clés ».
 */
final readonly class CredentialKinds
{
    /** @var array<string, CredentialKindInterface> */
    private array $kinds;

    /**
     * @param iterable<CredentialKindInterface> $kinds
     */
    public function __construct(
        #[AutowireIterator(CredentialKindInterface::TAG)]
        iterable $kinds,
    ) {
        $byId = [];
        foreach ($kinds as $kind) {
            $byId[$kind->id()] = $kind;
        }
        ksort($byId);
        $this->kinds = $byId;
    }

    /**
     * @return array<string, CredentialKindInterface>
     */
    public function all(): array
    {
        return $this->kinds;
    }

    public function get(string $id): ?CredentialKindInterface
    {
        return $this->kinds[$id] ?? null;
    }
}
