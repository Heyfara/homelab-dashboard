<?php

namespace App\Dashboard;

use App\Card\CardTypeRegistry;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Point d'entrée pour obtenir le dashboard : chargé, validé, options des cartes
 * résolues. Mis en cache tant que le fichier ne change pas (date de modification),
 * pour qu'un déploiement Ansible du YAML soit pris en compte sans redémarrage.
 */
final readonly class DashboardProvider
{
    public function __construct(
        private YamlDashboardLoader $loader,
        private DashboardValidator $validator,
        private CardTypeRegistry $registry,
        private CacheInterface $cache,
        #[Autowire('%env(DASHBOARD_CONFIG)%')]
        private string $configPath,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        /** Version du code des cartes ; calculée d'après les sources si absente */
        private ?string $codeVersion = null,
    ) {
    }

    /**
     * @throws InvalidDashboardException
     */
    public function get(): Dashboard
    {
        $path = $this->configPath();
        clearstatcache(true, $path);
        $mtime = is_file($path) ? filemtime($path) : 0;

        // Une exception dans le callback n'est pas mise en cache : une config
        // invalide est relue à chaque requête, jusqu'à correction.
        // Le code des cartes fait partie de la clé : il complète les options
        // (valeurs par défaut) ; un code modifié périme le dashboard en cache.
        $code = $this->codeVersion ?? self::codeVersion($this->projectDir);

        return $this->cache->get('dashboard.'.hash('xxh128', $path.'|'.$mtime.'|'.$code), fn (): Dashboard => $this->build($path));
    }

    /**
     * Empreinte des sources qui façonnent le dashboard (types de carte, modèle,
     * configuration) : leurs dates de modification. Figée dans une image de prod.
     */
    public static function codeVersion(string $projectDir): string
    {
        $files = [...glob($projectDir.'/src/Card/Type/*.php') ?: [], ...glob($projectDir.'/src/Dashboard/*.php') ?: [], ...glob($projectDir.'/src/Dashboard/Model/*.php') ?: []];

        return hash('xxh128', implode('|', array_map(static fn (string $f): string => $f.':'.filemtime($f), $files)));
    }

    public function configPath(): string
    {
        return str_starts_with($this->configPath, '/') ? $this->configPath : $this->projectDir.'/'.$this->configPath;
    }

    /**
     * Charge et valide un fichier, sans cache. Sert aussi à dashboard:lint.
     *
     * @throws InvalidDashboardException
     */
    public function build(string $path): Dashboard
    {
        $dashboard = $this->loader->load($path);

        $errors = $this->validator->validate($dashboard);
        if ([] !== $errors) {
            throw new InvalidDashboardException($errors);
        }

        return $dashboard->mapCards(fn (CardInstance $card): CardInstance => $card->withOptions($this->registry->resolveOptions($card, $dashboard)));
    }
}
