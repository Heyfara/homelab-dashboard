<?php

namespace App\Source;

/**
 * Identifiants d'une source, éventuellement propres à une personne : une carte
 * avec `credentials: zoe` lit GW2_API_KEY_ZOE au lieu de GW2_API_KEY.
 * Le YAML ne porte que ce nom ; la valeur vient de l'environnement (.env.local,
 * vault Ansible). Le nom est toujours accolé au nom de base de la source : une
 * carte ne peut pas lire une autre variable (APP_SECRET…).
 */
final readonly class Credentials
{
    private const VARIANT = '/^[A-Za-z0-9_]+$/';

    /**
     * @param array<string, mixed>|null $env variables d'environnement, pour les tests ; sinon celles du processus
     */
    public function __construct(
        private ?array $env = null,
    ) {
    }

    public static function isValidVariant(string $variant): bool
    {
        return 1 === preg_match(self::VARIANT, $variant);
    }

    /**
     * @param string      $base    variable de la source : GW2_API_KEY
     * @param string|null $variant nom de la personne ou du compte : zoe → GW2_API_KEY_ZOE
     *
     * @throws SourceUnavailableException variable absente ou vide
     */
    public function get(string $base, ?string $variant = null): string
    {
        if (null !== $variant && !self::isValidVariant($variant)) {
            throw new \InvalidArgumentException(\sprintf('Identifiants « %s » : lettres, chiffres et _ seulement', $variant));
        }
        $name = null === $variant ? $base : $base.'_'.strtoupper($variant);
        $value = $this->lookup($name);
        if (null === $value || '' === $value) {
            throw new SourceUnavailableException(\sprintf('Variable %s absente ou vide (identifiants de la carte)', $name));
        }

        return $value;
    }

    /** Même ordre de recherche que les %env()% de Symfony. */
    private function lookup(string $name): ?string
    {
        if (null !== $this->env) {
            return isset($this->env[$name]) ? (string) $this->env[$name] : null;
        }
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return false === $value || !\is_scalar($value) ? null : (string) $value;
    }
}
