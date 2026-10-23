<?php

namespace App\Profile\Kind;

use App\Profile\CredentialKindInterface;
use App\Profile\InvalidCredentialException;
use App\Source\Gw2\Gw2Client;
use App\Source\SourceUnavailableException;

/** Clé d'API Guild Wars 2 d'une personne, pour la carte `gw2`. */
final readonly class Gw2CredentialKind implements CredentialKindInterface
{
    /** Droits dont la carte gw2 a besoin */
    private const PERMISSIONS = ['account', 'wallet', 'progression', 'tradingpost'];

    public function __construct(
        private Gw2Client $gw2,
        private string $api = 'https://api.guildwars2.com',
    ) {
    }

    public function id(): string
    {
        return 'gw2';
    }

    public function cardType(): string
    {
        return 'gw2';
    }

    public function label(): string
    {
        return 'Guild Wars 2';
    }

    public function help(): string
    {
        return \sprintf('Créer une clé sur https://account.arena.net/applications en cochant : %s.', implode(', ', self::PERMISSIONS));
    }

    public function verify(#[\SensitiveParameter] string $key): string
    {
        $key = trim($key);
        if ('' === $key) {
            throw new InvalidCredentialException('Clé vide.');
        }

        try {
            $missing = array_values(array_diff(self::PERMISSIONS, $this->gw2->tokenInfo($this->api, $key)['permissions'] ?? []));
            if ([] !== $missing) {
                throw new InvalidCredentialException(\sprintf('Il manque des droits à cette clé : %s.', implode(', ', $missing)));
            }

            return (string) $this->gw2->account($this->api, $key)['name'];
        } catch (SourceUnavailableException $e) {
            throw new InvalidCredentialException(str_contains($e->getMessage(), 'refusée') ? 'Clé refusée par Guild Wars 2 : vérifier qu\'elle est complète.' : 'Guild Wars 2 ne répond pas, réessayer plus tard.', previous: $e);
        }
    }
}
