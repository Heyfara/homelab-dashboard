<?php

namespace App\Storage;

/**
 * Fichiers JSON privés d'un dossier (`<nom>.json`, 0600, dossier 0700, hors de
 * public/), réécrits sous verrou par renommage atomique. Base de ProfileFiles
 * et de DailyHistory.
 */
final readonly class JsonFiles
{
    private const NAME = '/^[a-z0-9][a-z0-9._-]*$/';

    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
    }

    /**
     * @return array<string, mixed> contenu du fichier, vide s'il n'existe pas
     */
    public function read(string $name): array
    {
        $file = $this->file($name);
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return \is_array($data) ? $data : [];
    }

    /**
     * Empreinte du fichier : change à chaque écriture ('-' s'il n'existe pas).
     */
    public function revision(string $name): string
    {
        $file = $this->file($name);

        return is_file($file) ? (string) hash_file('xxh128', $file) : '-';
    }

    /**
     * Lecture, modification et réécriture sous verrou.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    public function update(string $name, callable $change): void
    {
        $file = $this->file($name);
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException(\sprintf('Dossier de données impossible à créer : %s', $this->dir));
        }
        @chmod($this->dir, 0700);
        $lock = fopen($file.'.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException(\sprintf('Verrou de %s impossible à prendre', basename($file)));
        }
        @chmod($file.'.lock', 0600);

        try {
            $data = $change($this->read($name));
            $tmp = tempnam($this->dir, '.'.$name.'.');
            if (false === $tmp) {
                throw new \RuntimeException(\sprintf('Écriture de %s impossible', basename($file)));
            }
            chmod($tmp, 0600);
            file_put_contents($tmp, json_encode($data, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
            rename($tmp, $file);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    public function remove(string $name): void
    {
        $file = $this->file($name);
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function file(string $name): string
    {
        if (1 !== preg_match(self::NAME, $name) || str_contains($name, '..')) {
            throw new \InvalidArgumentException(\sprintf('Nom de fichier invalide : « %s »', $name));
        }

        return $this->dir.'/'.$name.'.json';
    }
}
