<?php

namespace App\Profile;

use App\Storage\JsonFiles;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fichiers JSON propres à chaque profil (« Ma config », ordre des cartes), dans
 * PROFILE_DATA_DIR : `<profil>.<nom>.json`, privés et réécrits sous verrou
 * (JsonFiles).
 */
final readonly class ProfileFiles
{
    private const NAME = '/^[a-z0-9][a-z0-9_-]*$/';

    private JsonFiles $files;

    public function __construct(
        #[Autowire('%env(default:profile_data_default_dir:PROFILE_DATA_DIR)%')]
        string $dir,
    ) {
        $this->files = new JsonFiles($dir);
    }

    /**
     * @return array<string, mixed> contenu du fichier, vide s'il n'existe pas
     */
    public function read(string $profile, string $name): array
    {
        return $this->files->read(self::file($profile, $name));
    }

    /**
     * Empreinte du fichier : change à chaque écriture ('-' s'il n'existe pas).
     */
    public function revision(string $profile, string $name): string
    {
        return $this->files->revision(self::file($profile, $name));
    }

    /**
     * Lecture, modification et réécriture sous verrou.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    public function update(string $profile, string $name, callable $change): void
    {
        $this->files->update(self::file($profile, $name), $change);
    }

    public function remove(string $profile, string $name): void
    {
        $this->files->remove(self::file($profile, $name));
    }

    private static function file(string $profile, string $name): string
    {
        return self::check($profile).'.'.self::check($name);
    }

    public static function check(string $name): string
    {
        if (1 !== preg_match(self::NAME, $name)) {
            throw new \InvalidArgumentException(\sprintf('Nom invalide : « %s »', $name));
        }

        return $name;
    }
}
