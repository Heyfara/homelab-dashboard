<?php

namespace App\Profile;

/**
 * Réglages non secrets choisis par chaque personne dans « Ma config » (le lieu
 * de la météo…), dans `<profil>.settings.json` (voir ProfileFiles). Les clés
 * d'API, elles, sont chiffrées à part (ProfileCredentials).
 */
final readonly class ProfileSettings
{
    private const FILE = 'settings';

    public function __construct(
        private ProfileFiles $files,
        /** Instant présent (ISO 8601), pour les tests ; sinon maintenant */
        private ?string $now = null,
    ) {
    }

    /**
     * @return array<string, mixed>|null le réglage, avec sa date (updatedAt), ou null s'il n'est pas choisi
     */
    public function get(string $profile, string $name): ?array
    {
        $value = $this->files->read($profile, self::FILE)['settings'][ProfileFiles::check($name)] ?? null;

        return \is_array($value) ? $value : null;
    }

    /**
     * Empreinte des réglages du profil : entre dans la clé de cache de ses cartes.
     */
    public function revision(string $profile): string
    {
        return $this->files->revision($profile, self::FILE);
    }

    /**
     * @param array<string, mixed> $value
     */
    public function set(string $profile, string $name, array $value): void
    {
        ProfileFiles::check($name);
        $value['updatedAt'] = (new \DateTimeImmutable($this->now ?? 'now'))->format(\DATE_ATOM);
        $this->write($profile, static function (array $settings) use ($name, $value): array {
            $settings[$name] = $value;

            return $settings;
        });
    }

    public function remove(string $profile, string $name): void
    {
        ProfileFiles::check($name);
        $this->write($profile, static function (array $settings) use ($name): array {
            unset($settings[$name]);

            return $settings;
        });
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function write(string $profile, callable $change): void
    {
        $this->files->update($profile, self::FILE, static function (array $data) use ($change): array {
            $settings = \is_array($data['settings'] ?? null) ? $data['settings'] : [];

            return ['version' => 1, 'settings' => $change($settings)];
        });
    }
}
