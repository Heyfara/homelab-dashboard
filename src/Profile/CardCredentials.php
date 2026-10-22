<?php

namespace App\Profile;

use App\Dashboard\Model\CardInstance;
use App\Source\Credentials;
use App\Source\SourceUnavailableException;

/**
 * La clé qu'une carte utilise, selon son option `credentials` :
 * - absente : la variable d'environnement de la source (GW2_API_KEY) ;
 * - `profile` : la clé saisie dans « Mes clés » par le profil de la carte ;
 * - un nom : la variable de cette personne (GW2_API_KEY_ZOE).
 */
final readonly class CardCredentials
{
    public const PROFILE = 'profile';

    public function __construct(
        private Credentials $environment,
        private ProfileCredentials $profiles,
    ) {
    }

    /**
     * @param string $envBase variable de la source : GW2_API_KEY
     * @param string $kind    service dans « Mes clés » : gw2 (CredentialKindInterface::id())
     *
     * @throws SourceUnavailableException aucune clé trouvée
     */
    public function resolve(CardInstance $card, string $envBase, string $kind): string
    {
        $option = $card->options['credentials'] ?? null;
        if (self::PROFILE !== $option) {
            return $this->environment->get($envBase, $option);
        }
        $key = null === $card->profile ? null : $this->profiles->get($card->profile, $kind);
        if (null === $key) {
            throw new SourceUnavailableException('Pas encore de clé pour ce profil : l\'ajouter dans « Mes clés ».');
        }

        return $key;
    }
}
