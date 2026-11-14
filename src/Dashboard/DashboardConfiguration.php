<?php

namespace App\Dashboard;

use App\Dashboard\Model\Profile;
use App\Dashboard\Model\Section;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Structure du fichier dashboard.yaml. Les options propres à chaque type de
 * carte ne sont pas décrites ici : elles sont validées par le type lui-même
 * (voir DashboardValidator).
 */
final class DashboardConfiguration implements ConfigurationInterface
{
    private const SLUG = '/^[a-z0-9][a-z0-9-]*$/';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('dashboard');
        $root = $tree->getRootNode();

        $root
            ->children()
                ->arrayNode('settings')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('columns')->min(1)->max(12)->defaultValue(4)->end()
                        ->integerNode('refresh')->min(5)->defaultValue(30)->info('secondes')->end()
                    ->end()
                ->end()
                ->arrayNode('hosts')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('beszel')->defaultNull()->info('nom du système dans Beszel')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('services')
                    ->useAttributeAsKey('id')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('description')->defaultNull()->end()
                            ->scalarNode('url')->defaultNull()->end()
                            ->scalarNode('check')->defaultNull()->end()
                            ->scalarNode('icon')->defaultNull()->end()
                            ->scalarNode('host')->defaultNull()->end()
                            ->scalarNode('container')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('notifications')
                    ->info('serveur ntfy : messages des applications et des scripts (cloche)')
                    ->children()
                        ->scalarNode('url')->isRequired()->cannotBeEmpty()->info('API ntfy, vue du serveur du dashboard')->end()
                        ->scalarNode('link')->defaultNull()->info('interface web de ntfy, pour le navigateur')->end()
                        ->arrayNode('topics')
                            ->info('sujet ntfy → nom affiché')
                            ->useAttributeAsKey('id')
                            ->normalizeKeys(false) // garder les tirets (maison-critique)
                            ->validate()
                                ->ifTrue(static fn (array $t): bool => [] !== self::invalidTopics(array_keys($t)))
                                ->then(static fn (array $t) => throw new \InvalidArgumentException(\sprintf('Sujet ntfy invalide : « %s » (lettres, chiffres, - et _).', implode('», «', self::invalidTopics(array_keys($t))))))
                            ->end()
                            ->arrayPrototype()
                                ->beforeNormalization()
                                    ->ifTrue(static fn ($v): bool => null === $v || \is_string($v))
                                    ->then(static fn (?string $v): array => ['name' => $v])
                                ->end()
                                ->children()
                                    ->scalarNode('name')->defaultNull()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('profiles')
                    ->useAttributeAsKey('slug')
                    ->validate()
                        ->ifTrue(static fn (array $p): bool => [] !== self::invalidSlugs(array_keys($p)))
                        ->then(static fn (array $p) => throw new \InvalidArgumentException(\sprintf('Identifiant de profil invalide : « %s » (minuscules, chiffres et tirets).', implode('», «', self::invalidSlugs(array_keys($p))))))
                    ->end()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                            ->enumNode('color')->values(Profile::COLORS)->defaultValue('slate')->end()
                            ->arrayNode('notifications')
                                ->info('sujets ntfy suivis par le profil (cloche) ; vide = pas de cloche')
                                ->scalarPrototype()->end()
                            ->end()
                            ->arrayNode('sections')
                                ->arrayPrototype()
                                    ->children()
                                        ->scalarNode('title')->isRequired()->end()
                                        ->integerNode('columns')->min(1)->max(12)->defaultNull()->end()
                                        ->scalarNode('tab')
                                            ->defaultValue(Section::DEFAULT_TAB)
                                            ->info('onglet où la section s\'affiche')
                                            ->validate()
                                                ->ifTrue(static fn ($tab): bool => '' === Section::tabSlug((string) $tab))
                                                ->thenInvalid('Nom d\'onglet sans lettre ni chiffre : %s.')
                                            ->end()
                                            ->validate()
                                                ->ifTrue(static fn ($tab): bool => \in_array(Section::tabSlug((string) $tab), Section::RESERVED_TABS, true))
                                                ->thenInvalid('Nom d\'onglet réservé (adresse de « Ma config ») : %s.')
                                            ->end()
                                        ->end()
                                        ->arrayNode('cards')
                                            ->arrayPrototype()
                                                // Les clés non réservées sont les options du type de carte.
                                                ->ignoreExtraKeys(false)
                                                ->children()
                                                    ->scalarNode('type')->isRequired()->cannotBeEmpty()->end()
                                                    ->scalarNode('id')
                                                        ->defaultNull()
                                                        ->validate()
                                                            ->ifTrue(static fn ($id): bool => null !== $id && !preg_match(self::SLUG, (string) $id))
                                                            ->thenInvalid('Identifiant de carte invalide : %s (minuscules, chiffres et tirets).')
                                                        ->end()
                                                    ->end()
                                                    ->scalarNode('title')->defaultNull()->end()
                                                    ->integerNode('span')->min(1)->max(12)->defaultValue(1)->info('largeur, en colonnes')->end()
                                                    ->integerNode('rows')->min(1)->max(6)->defaultValue(1)->info('hauteur, en rangées')->end()
                                                    ->integerNode('refresh')->min(5)->defaultNull()->end()
                                                ->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $tree;
    }

    /**
     * @param list<int|string> $topics
     *
     * @return list<string>
     */
    private static function invalidTopics(array $topics): array
    {
        return array_values(array_filter(array_map('strval', $topics), static fn (string $t): bool => !preg_match('/^[-_A-Za-z0-9]{1,64}$/', $t)));
    }

    /**
     * @param list<int|string> $slugs
     *
     * @return list<string>
     */
    private static function invalidSlugs(array $slugs): array
    {
        return array_values(array_filter(array_map('strval', $slugs), static fn (string $s): bool => !preg_match(self::SLUG, $s)));
    }
}
