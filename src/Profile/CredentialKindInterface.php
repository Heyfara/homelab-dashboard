<?php

namespace App\Profile;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un service dont chaque personne peut saisir sa clé dans « Ma config ».
 * Les cartes qui l'utilisent prennent l'option `credentials: profile`.
 */
#[AutoconfigureTag(self::TAG)]
interface CredentialKindInterface
{
    public const TAG = 'app.credential_kind';

    /** Identifiant, aussi utilisé dans le fichier du profil : gw2 */
    public function id(): string;

    /** Type de la carte qui utilise cette clé : la section n'apparaît que si le profil en a une, en credentials: profile */
    public function cardType(): string;

    /** Nom affiché : Guild Wars 2 */
    public function label(): string;

    /** Où créer la clé et quels droits cocher, pour l'écran */
    public function help(): string;

    /**
     * Vérifie la clé auprès du service, avant de l'enregistrer.
     *
     * @return string le compte trouvé, affiché ensuite (« Zoe.1234 »)
     *
     * @throws InvalidCredentialException clé refusée ou droits insuffisants, message pour l'écran
     */
    public function verify(#[\SensitiveParameter] string $key): string;
}
