# Développement

L'environnement de dev tourne dans Docker (`compose.yaml`) et lit le **vrai
homelab en lecture seule** : l'état des containers et des machines vient du hub
Beszel du homelab, les sondes visent les `https://<service>.example`.

## Une fois

### 1. Compte Beszel en lecture seule

Dans l'administration PocketBase du hub, <https://monitor.example/_/> (compte
superuser) :

1. Collection **users** → *New record* : un e-mail (ex. `dashboard@maison.example`),
   un mot de passe, **role = `readonly`**, *verified* coché.
2. Collection **systems** → pour **Alpha**, **Beta** et **Gamma** : ajouter
   ce compte dans le champ **users**. Un compte ne voit que les systèmes dont
   il est membre.

Le mot de passe va dans Vaultwarden (et dans le vault Ansible pour la prod).
Le rôle `readonly` ne peut rien modifier dans Beszel. Il peut en revanche lire
les logs des containers dans l'interface, comme tout compte Beszel.

### 2. `.env.local` (non versionné)

```dotenv
DASHBOARD_CONFIG=dashboard.local.yaml
BESZEL_URL=https://monitor.example
BESZEL_EMAIL=dashboard@maison.example
BESZEL_PASSWORD=…
JELLYFIN_API_KEY=…      # Jellyfin → Tableau de bord → Clés API → +, nommée « Dashboard »
QBITTORRENT_USERNAME=…  # compte WebUI de qBittorrent
QBITTORRENT_PASSWORD=…
C411_API_SECRET=…       # clé C411, scope « Profil (lecture) »
GITHUB_TOKEN=…          # jeton fine-grained, lecture seule (Pull requests + Issues)
B2_KEY_ID=…             # clé d'application Backblaze, type Read Only
B2_APPLICATION_KEY=…
TLS_CA_FILE=/certs/mkcert-rootCA.pem
```

`compose.yaml` monte la CA mkcert du poste (`~/.local/share/mkcert/rootCA.pem`,
ou `$MKCERT_CAROOT/rootCA.pem`) sur `/certs/mkcert-rootCA.pem`. Sans ce
fichier, le container refuse de démarrer.

### 3. `dashboard.local.yaml` (non versionné)

La config réelle du dev : catalogue des services du homelab et profils. Les
sondes y passent par `url` (`https://<service>.example`), joignables depuis le PC.
Partir de `examples/dashboard.yaml` et de `docs/configuration.md`.

> **Docker Desktop** : après avoir modifié `dashboard.local.yaml`, si le
> changement n'apparaît pas, voir *Une modification du YAML n'apparaît pas*
> dans `docs/depannage.md` (cache des fichiers remplacés par renommage).

### 4. `compose.override.yaml` (non versionné) : DNS du homelab

Sous Docker Desktop, le relais DNS de la VM résout mal les noms locaux du homelab :
réponses entre 0,7 et 4 s, parfois un échec, alors que Pi-hole répond en
quelques ms. Les sondes HTTP (3 s) affichent alors « ne répond pas » au
hasard. Donner Pi-hole en direct au container :

```yaml
# compose.override.yaml, chargé automatiquement par docker compose
services:
  dashboard:
    dns: [10.0.0.35]
```

Puis `docker compose up -d dashboard`. L'adresse reste hors du dépôt (image
et dépôt publics).

### 5. « Mes clés » et ordre des cartes en dev

Les clés saisies dans « Mes clés » et l'ordre des cartes (« Réorganiser ») vont
dans `var/profiles/` (ignoré par git) ; les clés sont chiffrées avec
l'`APP_SECRET` du dev. Les tests utilisent un dossier à part,
dans le cache de test.

## Au quotidien

```bash
make up          # http://localhost:5173 (front, rechargement à chaud)
make test        # tests PHP + front
make lint        # conteneur Symfony, YAML, dashboard.local.yaml
make down
```

Les tests n'utilisent ni `.env.local` ni le réseau : `.env.test` et
`tests/Http/FakeHomelab.php` les remplacent.
