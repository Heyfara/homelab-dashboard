# AGENTS.md

Dashboard du homelab : API Symfony 8 (PHP 8.5, PHP-FPM) + front React/Vite/
Tailwind v4 dans `assets/`. Lire `docs/architecture.md` avant toute modification
de structure, `docs/ajouter-une-carte.md` avant d'ajouter un type de carte.

## Règles du projet

- **TDD** : écrire d'abord le test qui échoue, puis le code. Un correctif de bug
  commence par un test qui reproduit le bug. Pas de PR sans test.
- **Dépendances au strict nécessaire** : pas de bundle tiers, pas de lib qu'on
  remplacerait par quelques dizaines de lignes. Composants Symfony de base OK.
  Toute nouvelle dépendance se justifie dans la PR et dans `docs/architecture.md`.
- **Aucune donnée personnelle dans le dépôt** : l'image est publique. La vraie
  configuration et les secrets viennent du rôle Ansible `service-dashboard`.
  `examples/` et `tests/fixtures/` restent fictifs.
- **Versions épinglées** (`.npmrc` save-exact, tags d'images exacts) : Renovate
  les met à jour.
- **Textes en français** (messages d'erreur, interface, commentaires, docs).
- **Tenir la doc à jour** dans la même PR : `docs/configuration.md` pour tout
  changement du YAML ou nouveau type de carte, `docs/depannage.md` pour toute
  panne dont la cause n'était pas évidente.

## Commandes

Mise en route du dev (compte Beszel, `.env.local`, `dashboard.local.yaml`) :
`docs/developpement.md`. Le dev lit le vrai homelab **en lecture seule** ; ne
jamais y écrire.


Tout passe par Docker (`make help`). Exemples :

```bash
make test-php ARGS="--filter ServiceCard"
make test-front ARGS="cards/ServiceCard"
make lint
docker compose run --rm dashboard bin/console debug:router
```

## Conventions

- Attributs PHP pour le framework (`#[Route]`, `#[AsCommand]`,
  `#[AsEventListener]`, `#[Autowire]`), autowiring partout, pas de YAML de
  service sauf `config/services_test.yaml`.
- Contrôleurs minces : un `__invoke`, la logique dans `src/Dashboard`, `src/Card`.
- Objets du modèle et résultats en `final readonly`.
- Les types TypeScript de `assets/http/types.ts` et des cartes reflètent les
  tableaux renvoyés par les contrôleurs et par `describe()`/`fetch()` : modifier
  les deux ensemble.
- Liens des cartes : composant `ExternalLink` (nouvel onglet), jamais de `<a>` brut
  (vérifié par `assets/ui/ExternalLink.test.tsx`).
- Aucun test ne sort sur le réseau : `tests/Http/FakeHomelab.php` (PHP) et
  `fetch` simulé (vitest).
- Style PHP : `@Symfony` (php-cs-fixer non installé : suivre le style existant).

## Découvrir plutôt que deviner

`bin/console about`, `debug:router`, `debug:container`, `debug:autowiring`,
`config:dump-reference framework`, et les sources dans `vendor/`. Les API de
Symfony 8, React 19, Vite 8, Vitest 5 et TypeScript 7 ont pu changer depuis tes
connaissances.
