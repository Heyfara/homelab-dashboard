<?php

namespace App\Dashboard;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Host;
use App\Dashboard\Model\Profile;
use App\Dashboard\Model\Section;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Lit dashboard.yaml, en vérifie la structure et construit le modèle.
 * Ne vérifie pas les renvois (type de carte, service…) : c'est DashboardValidator.
 */
final class YamlDashboardLoader
{
    public function load(string $path): Dashboard
    {
        if (!is_file($path)) {
            throw new InvalidDashboardException([\sprintf('Fichier de configuration introuvable : %s', $path)]);
        }

        try {
            $raw = Yaml::parseFile($path) ?? [];
        } catch (ParseException $e) {
            throw new InvalidDashboardException([\sprintf('YAML invalide : %s', $e->getMessage())], $e);
        }

        try {
            $config = (new Processor())->processConfiguration(new DashboardConfiguration(), [$raw]);
        } catch (InvalidConfigurationException|\InvalidArgumentException $e) {
            throw new InvalidDashboardException([$e->getMessage()], $e);
        }

        return $this->build($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function build(array $config): Dashboard
    {
        $settings = new Settings($config['settings']['columns'], $config['settings']['refresh']);

        $hosts = [];
        foreach ($config['hosts'] as $name => $host) {
            $hosts[$name] = new Host($name, $host['beszel']);
        }

        $services = [];
        foreach ($config['services'] as $id => $s) {
            $services[$id] = new ServiceDefinition($id, $s['name'], $s['description'], $s['url'], $s['check'], $s['icon'], $s['host'], $s['container']);
        }

        $profiles = [];
        foreach ($config['profiles'] as $slug => $p) {
            $sections = [];
            $derived = [];
            foreach ($p['sections'] as $s) {
                $cards = [];
                foreach ($s['cards'] as $c) {
                    $options = array_diff_key($c, array_flip(CardInstance::RESERVED_KEYS));
                    $cards[] = new CardInstance(
                        id: $c['id'] ?? self::derivedId($slug, $c['type'], $options, $derived),
                        type: $c['type'],
                        title: $c['title'],
                        span: $c['span'],
                        refresh: $c['refresh'] ?? $settings->refresh,
                        options: $options,
                        rows: $c['rows'],
                        profile: $slug,
                    );
                }
                $sections[] = new Section($s['title'], $s['columns'] ?? $settings->columns, $cards);
            }
            $profiles[$slug] = new Profile($slug, $p['name'], $p['color'], $sections);
        }

        return new Dashboard($settings, $hosts, $services, $profiles);
    }

    /**
     * Identifiant d'une carte sans `id:` : profil, type et empreinte des options.
     * Il ne dépend pas de la position, pour que l'ordre enregistré par une
     * personne survive aux ajouts et déplacements dans le YAML. Deux cartes
     * identiques du même profil sont numérotées (-2, -3…).
     *
     * @param array<string, mixed> $options
     * @param array<string, int>   $seen    identifiants déjà donnés dans le profil
     */
    private static function derivedId(string $profile, string $type, array $options, array &$seen): string
    {
        ksort($options);
        $id = \sprintf('%s-%s-%s', $profile, $type, substr(hash('xxh32', serialize($options)), 0, 6));
        $seen[$id] = ($seen[$id] ?? 0) + 1;

        return 1 === $seen[$id] ? $id : $id.'-'.$seen[$id];
    }
}
