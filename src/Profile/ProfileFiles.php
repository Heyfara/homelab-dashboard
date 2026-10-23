<?php

namespace App\Profile;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fichiers JSON propres à chaque profil (« Ma config », ordre des cartes), dans
 * PROFILE_DATA_DIR : `<profil>.<nom>.json`, privés (0600, dossier 0700, hors
 * de public/), réécrits sous verrou par renommage atomique.
 */
final readonly class ProfileFiles
{
    private const NAME = '/^[a-z0-9][a-z0-9_-]*$/';

    private string $dir;

    public function __construct(
        #[Autowire('%env(default:profile_data_default_dir:PROFILE_DATA_DIR)%')]
        string $dir,
    ) {
        $this->dir = rtrim($dir, '/');
    }

    /**
     * @return array<string, mixed> contenu du fichier, vide s'il n'existe pas
     */
    public function read(string $profile, string $name): array
    {
        $file = $this->file($profile, $name);
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return \is_array($data) ? $data : [];
    }

    /**
     * Empreinte du fichier : change à chaque écriture ('-' s'il n'existe pas).
     */
    public function revision(string $profile, string $name): string
    {
        $file = $this->file($profile, $name);

        return is_file($file) ? (string) hash_file('xxh128', $file) : '-';
    }

    /**
     * Lecture, modification et réécriture sous verrou.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    public function update(string $profile, string $name, callable $change): void
    {
        $file = $this->file($profile, $name);
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(\sprintf('Dossier des profils impossible à créer : %s', $this->dir));
        }
        @chmod($this->dir, 0700);
        $lock = fopen($file.'.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('Verrou du fichier de profil impossible à prendre');
        }
        @chmod($file.'.lock', 0600);

        try {
            $data = $change($this->read($profile, $name));
            $tmp = tempnam($this->dir, '.'.$profile.'.');
            if (false === $tmp) {
                throw new \RuntimeException('Écriture du fichier de profil impossible');
            }
            chmod($tmp, 0600);
            file_put_contents($tmp, json_encode($data, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
            rename($tmp, $file);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    public function remove(string $profile, string $name): void
    {
        $file = $this->file($profile, $name);
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function file(string $profile, string $name): string
    {
        return $this->dir.'/'.self::check($profile).'.'.self::check($name).'.json';
    }

    public static function check(string $name): string
    {
        if (1 !== preg_match(self::NAME, $name)) {
            throw new \InvalidArgumentException(\sprintf('Nom invalide : « %s »', $name));
        }

        return $name;
    }
}
