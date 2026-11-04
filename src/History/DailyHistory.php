<?php

namespace App\History;

use App\Storage\JsonFiles;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Relevés quotidiens que les sources ne gardent pas (taille des buckets B2…),
 * un fichier JSON par série dans HISTORY_DATA_DIR : `{"2026-11-04": {…}, …}`.
 * Une mesure par jour : la dernière du jour remplace la précédente, valeur par
 * valeur (deux cartes qui relèvent des buckets différents se complètent).
 */
final readonly class DailyHistory
{
    private JsonFiles $files;

    public function __construct(
        #[Autowire('%env(default:history_data_default_dir:HISTORY_DATA_DIR)%')]
        string $dir,
        private ?string $now = null,
    ) {
        $this->files = new JsonFiles($dir);
    }

    /**
     * @param array<string, int|float> $values
     * @param int                      $keep   jours conservés, les plus anciens sont oubliés
     */
    public function record(string $series, array $values, int $keep = 1100): void
    {
        $today = (new \DateTimeImmutable($this->now ?? 'now'))->format('Y-m-d');

        $this->files->update($series, static function (array $days) use ($today, $values, $keep): array {
            $days[$today] = [...\is_array($days[$today] ?? null) ? $days[$today] : [], ...$values];
            ksort($days);

            return \array_slice($days, -$keep, preserve_keys: true);
        });
    }

    /**
     * @return array<string, array<string, int|float>> valeurs par jour (Y-m-d), du plus ancien au plus récent
     */
    public function read(string $series): array
    {
        $days = array_filter($this->files->read($series), \is_array(...));
        ksort($days);

        return $days;
    }
}
