<?php

namespace App\Profile;

use App\Dashboard\Model\Profile;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tous les services dont on peut saisir sa clé dans « Ma config ».
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

    /**
     * Services dont une carte du profil prend la clé du profil (credentials:
     * profile) : seules sections de clés à montrer dans sa « Ma config ».
     *
     * @return array<string, CredentialKindInterface>
     */
    public function usedBy(Profile $profile): array
    {
        $types = [];
        foreach ($profile->cards() as $card) {
            if (CardCredentials::PROFILE === ($card->options['credentials'] ?? null)) {
                $types[$card->type] = true;
            }
        }

        return array_filter($this->kinds, static fn (CredentialKindInterface $k): bool => isset($types[$k->cardType()]));
    }

    public function get(string $id): ?CredentialKindInterface
    {
        return $this->kinds[$id] ?? null;
    }
}
