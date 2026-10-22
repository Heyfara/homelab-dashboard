<?php

namespace App\Profile;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Clés d'API saisies par chaque personne depuis son profil (« Mes clés »).
 *
 * Fichier `<profil>.credentials.json` (voir ProfileFiles : privé, hors de public/).
 * Chaque valeur est chiffrée (XChaCha20-Poly1305) avec une clé dérivée
 * d'APP_SECRET, et liée à son profil et à son service : recopiée ailleurs, elle
 * ne se déchiffre plus. Changer APP_SECRET rend les clés illisibles (à
 * ressaisir), sans erreur. Seule une description non secrète (le compte
 * vérifié) est en clair, pour l'écran.
 */
final readonly class ProfileCredentials
{
    private const FILE = 'credentials';

    private string $key;

    public function __construct(
        private ProfileFiles $files,
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        string $secret,
        /** Instant présent (ISO 8601), pour les tests ; sinon maintenant */
        private ?string $now = null,
    ) {
        $this->key = sodium_crypto_generichash('homelab-dashboard/profile-credentials', sodium_crypto_generichash($secret), \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }

    public function get(string $profile, string $kind): ?string
    {
        $entry = $this->read($profile)[ProfileFiles::check($kind)] ?? null;
        $raw = base64_decode((string) ($entry['value'] ?? ''), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, \strlen($nonce)), $profile.'|'.$kind, $nonce, $this->key);

        return false === $plain ? null : $plain;
    }

    /**
     * Empreinte des clés du profil : change à chaque ajout, remplacement ou
     * suppression. Entre dans la clé de cache des cartes du profil.
     */
    public function revision(string $profile): string
    {
        return $this->files->revision($profile, self::FILE);
    }

    /**
     * Services configurés, sans les valeurs.
     *
     * @return array<string, array{description: ?string, updatedAt: ?string}>
     */
    public function list(string $profile): array
    {
        return array_map(static fn (array $e): array => ['description' => $e['description'] ?? null, 'updatedAt' => $e['updatedAt'] ?? null], $this->read($profile));
    }

    public function set(string $profile, string $kind, #[\SensitiveParameter] string $value, ?string $description): void
    {
        ProfileFiles::check($kind);
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, $profile.'|'.$kind, $nonce, $this->key);
        $now = (new \DateTimeImmutable($this->now ?? 'now'))->format(\DATE_ATOM);

        $this->write($profile, static function (array $entries) use ($kind, $nonce, $cipher, $description, $now): array {
            $entries[$kind] = ['value' => base64_encode($nonce.$cipher), 'description' => $description, 'updatedAt' => $now];

            return $entries;
        });
    }

    public function remove(string $profile, string $kind): void
    {
        ProfileFiles::check($kind);
        $this->write($profile, static function (array $entries) use ($kind): array {
            unset($entries[$kind]);

            return $entries;
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(string $profile): array
    {
        $credentials = $this->files->read($profile, self::FILE)['credentials'] ?? null;

        return \is_array($credentials) ? $credentials : [];
    }

    /**
     * @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $change
     */
    private function write(string $profile, callable $change): void
    {
        $this->files->update($profile, self::FILE, static function (array $data) use ($change): array {
            $credentials = \is_array($data['credentials'] ?? null) ? $data['credentials'] : [];

            return ['version' => 1, 'credentials' => $change($credentials)];
        });
    }
}
