# homelab-dashboard

Page d'accueil du homelab, en remplacement de Homepage. Chaque membre de la
famille choisit son profil (sans mot de passe) et voit son propre dashboard :
des cartes par service, par machine, etc., alimentées par les vraies sources
du homelab (Beszel, sondes HTTP, API des services).

- **Back** : Symfony 8 en API JSON seule, PHP-FPM.
- **Front** : React + Vite + Tailwind v4, dans `assets/`.
- **Config** : un fichier YAML (profils → sections → cartes), fourni par le
  rôle Ansible `service-dashboard` en prod, `examples/dashboard.yaml` en dev.
- **Prod** : image `ghcr.io/heyfara/homelab-dashboard`, derrière le nginx
  partagé du serveur, lui-même derrière Caddy.

## Démarrer

```bash
make install   # composer install + npm ci (dans Docker)
make up        # front sur http://localhost:5173, API sur http://localhost:8080/api/profiles
make test      # tous les tests
make help      # toutes les commandes
```

Le dev affiche le vrai homelab, en lecture seule : compte Beszel, `.env.local`
et `dashboard.local.yaml` à préparer une fois, voir
[docs/developpement.md](docs/developpement.md).

## Documentation

| Document | Pour |
|---|---|
| [docs/developpement.md](docs/developpement.md) | mettre en route l'environnement de dev |
| [docs/architecture.md](docs/architecture.md) | comprendre le découpage, les flux, les choix |
| [docs/configuration.md](docs/configuration.md) | écrire ou modifier un `dashboard.yaml` |
| [docs/ajouter-une-carte.md](docs/ajouter-une-carte.md) | créer un nouveau type de carte, pas à pas, tests d'abord |
| [docs/depannage.md](docs/depannage.md) | une carte en erreur, une config refusée, la CI rouge |
| [AGENTS.md](AGENTS.md) | règles du projet pour les agents IA (et les humains) |

## Livrer une version

1. Tests verts sur `main` (CI).
2. `git tag v0.2.0 && git push --tags` : GitHub Actions publie l'image.
3. Renovate ouvre la PR de montée de version dans `homelab-ansible` ; la merger déploie.
