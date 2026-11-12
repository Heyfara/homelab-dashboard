# Configuration : `dashboard.yaml`

Le chemin du fichier vient de `DASHBOARD_CONFIG` (absolu, ou relatif à la racine
du projet). Il est relu dès que sa date de modification change.

Variables d'environnement liées :

| Variable | Rôle |
|---|---|
| `DASHBOARD_CONFIG` | chemin de ce fichier |
| `BESZEL_URL`, `BESZEL_EMAIL`, `BESZEL_PASSWORD` | hub Beszel et compte en lecture seule |
| `JELLYFIN_API_KEY` | clé API Jellyfin dédiée au dashboard (cartes `jellyfin-latest`) |
| `QBITTORRENT_USERNAME`, `QBITTORRENT_PASSWORD` | compte WebUI qBittorrent (cartes `qbittorrent`) |
| `C411_API_SECRET` | clé API C411 avec le scope « Profil (lecture) » (cartes `c411`) |
| `ULTRACC_API_TOKEN` | jeton du script Ultra-API d'Ultra.cc (cartes `ultracc`) |
| `GITHUB_TOKEN` | jeton GitHub en lecture seule (cartes `github-prs`) ; vide = dépôts publics seulement |
| `B2_KEY_ID`, `B2_APPLICATION_KEY` | clé d'application Backblaze B2 en lecture seule (cartes `backblaze`) |
| `MEALIE_API_TOKEN` | jeton d'API Mealie (cartes `mealie`) |
| `HOME_ASSISTANT_TOKEN` | jeton longue durée d'un utilisateur Home Assistant dédié, **non administrateur** (cartes de Home Assistant, API WebSocket) |
| `AUDIOBOOKSHELF_API_KEY` | clé d'API Audiobookshelf (cartes `audiobookshelf`) ; clé d'un admin pour suivre un autre utilisateur |
| `CWA_USERNAME`, `CWA_PASSWORD` | compte Calibre-Web sans droits d'écriture (cartes `calibre-web`, flux OPDS) |
| `IMMICH_API_KEY` | clé d'API Immich (cartes `immich`) : permissions `asset.read`, `asset.view`, `asset.statistics`, `memory.read` |
| `MUSIC_ASSISTANT_TOKEN` | jeton longue durée Music Assistant d'un utilisateur au rôle Invité (cartes `music-assistant`) |
| `JELU_API_TOKEN` | jeton d'API Jelu avec le scope `reading:read` (cartes `jelu`) |
| `CRAFTY_API_TOKEN` | clé d'API Crafty Controller, sans accès complet (cartes `crafty`) |
| `HEALTHCHECKS_API_KEY` | clé API (lecture-écriture) du projet Healthchecks (cartes `backups` et `doc-fetcher`) : la clé en lecture seule ne donne pas les rapports des pings. Le dashboard ne fait que lire |
| `GW2_API_KEY` | clé d'API Guild Wars 2 du compte (cartes `gw2`) : droits `account`, `wallet`, `progression`, `tradingpost` |
| `PROFILE_DATA_DIR` | dossier des données saisies par chaque profil : clés et réglages de « Ma config » (clés chiffrées) et ordre des cartes ; vide = `var/profiles`. En prod : un volume privé, sauvegardé |
| `TLS_CA_FILE` | CA à approuver pour Beszel et les sondes HTTPS (CA mkcert), vide = CA du système |

### Identifiants par personne (`credentials`)

Certaines cartes montrent les données d'un compte précis, par exemple Guild
Wars 2. L'option `credentials` dit quelle clé prendre :

```yaml
- { type: gw2 }                         # GW2_API_KEY (environnement)
- { type: gw2, credentials: profile }   # la clé saisie dans « Ma config » par le profil de la carte
- { type: gw2, credentials: zoe }   # GW2_API_KEY_ZOE (environnement)
```

- **`profile`** : chacun colle sa clé lui-même, depuis le bouton « Ma config »
  de son dashboard (`/<profil>/config`). La clé est vérifiée, puis gardée chiffrée
  sur le serveur (voir `docs/architecture.md`). Tant qu'elle manque, la carte
  affiche « Pas encore de clé pour ce profil : l'ajouter dans « Ma config ». ».
  La section du service n'apparaît dans « Ma config » que si le profil a une
  carte de ce type en `credentials: profile`.
- **Un nom** : la clé est posée par l'admin dans l'environnement ; le YAML ne
  contient que le nom (`.env.local` en dev, vault Ansible en prod).
- Le nom n'accepte que lettres, chiffres et `_`, et il est toujours accolé au
  nom de base de la source : une carte ne peut pas lire une autre variable.
- Chaque variante a son propre cache.
- Cartes qui l'acceptent : `gw2`. Pour l'ajouter à une autre carte, voir
  `docs/ajouter-une-carte.md`.

Vérifier un fichier avant de le déployer :

```bash
make lint                                             # examples/dashboard.yaml
docker compose run --rm dashboard bin/console dashboard:lint chemin/vers/dashboard.yaml
```

Toutes les erreurs sont listées d'un coup. En prod, une config invalide
affiche cette même liste dans la page.

## Structure

```yaml
settings:
  columns: 4        # colonnes de la grille sur grand écran (1-12), défaut 4
  refresh: 30       # secondes entre deux rafraîchissements (≥ 5), défaut 30

hosts:              # machines, référencées par les services
  alpha:
    beszel: Alpha                        # nom exact du système dans Beszel (majuscule comprise)
  gamma: ~                               # sans Beszel ou sans Docker : pas d'état de container

services:           # catalogue partagé par tous les profils
  immich:
    name: Immich                         # obligatoire
    description: Photos de la famille
    url: https://immich.example             # ouvert par le navigateur
    check: http://10.0.0.2:2283          # testé par le serveur (sinon url)
    icon: sh-immich                      # icône selfh.st, ou une URL d'image (voir « Icônes »)
    host: alpha                          # doit exister dans hosts
    container: immich_server             # nom du container (tel que Beszel le liste)

profiles:
  tom:                                # identifiant = URL /tom (minuscules, chiffres, tirets)
    name: Tom
    color: indigo                        # slate, indigo, sky, emerald, amber, rose, violet, teal
    sections:
      - title: Services
        columns: 3                       # optionnel, sinon settings.columns
        cards:
          - type: service                # type de carte
            service: immich              # options du type (voir plus bas)
      - title: Machines
        tab: Admin                       # onglet de la section, défaut « Accueil »
        cards:
          - { type: machine, host: alpha }
```

### Onglets (`tab`)

Chaque section s'affiche dans un onglet : `tab: <nom>`, « Accueil » par défaut.
Les onglets suivent l'ordre de leur première section. Un profil dont toutes
les sections sont dans le même onglet n'affiche pas de barre d'onglets.

- Adresse : le premier onglet est `/<profil>`, les autres `/<profil>/<onglet>`,
  avec le nom en minuscules sans accents (« Médias et jeux » → `/tom/medias-et-jeux`).
- `config` et `cles` sont réservés (adresses de « Ma config »).
- Seules les cartes de l'onglet ouvert sont chargées.
- Icône de l'onglet : maison pour le premier, bouclier pour `Admin`, imprimante 3D pour `Atelier`, grille pour les autres.
- Sur ordinateur, les onglets sont dans une barre latérale toujours visible (icônes seules en dessous de 1024 px de large ; au-delà, bouton en haut de la barre pour passer en icônes seules, retenu par l'appareil) ; sur téléphone, en barre fixe en bas.
- « Réorganiser » déplace les cartes dans leur section, comme avant : changer une
  section d'onglet garde l'ordre choisi.

## Icônes

Même convention que Homepage. Les noms sont ceux de <https://selfh.st/icons>
(colonne *Reference* de `https://cdn.jsdelivr.net/gh/selfhst/icons/index.json`).

| Valeur | Image |
|---|---|
| `sh-immich` | WebP, disponible pour toutes les icônes (par défaut) |
| `sh-immich.svg`, `sh-immich.png` | format forcé ; le SVG n'existe pas pour toutes |
| `sh-immich-light.svg`, `sh-immich-dark.svg` | variantes claire/sombre, quand elles existent |
| `https://…` | image quelconque |
| `ultracc.svg` | fichier livré avec le dashboard, dans `assets/public/icons/` (logos absents de selfh.st) |

Une icône introuvable affiche l'initiale du nom du service.

## Clés communes à toutes les cartes

| Clé | Défaut | Rôle |
|---|---|---|
| `type` | obligatoire | type de carte (liste : `bin/console dashboard:card-types`) |
| `id` | `<profil>-<type>-<empreinte>` | identifiant dans l'URL de l'API et dans l'ordre choisi par la personne (« Réorganiser »). Calculé d'après le type et les options : déplacer la carte dans le YAML ne le change pas, changer une option si. À fixer pour garder sa place après un changement d'options |
| `title` | `null` | titre affiché, si le type en utilise un |
| `span` | `1` | largeur en colonnes (plafonnée au nombre de colonnes) |
| `rows` | `1` | hauteur en rangées (1 à 6). Toutes les rangées ont la hauteur d'une carte `service` : une carte `span: 2, rows: 2` occupe exactement la place de quatre |
| `refresh` | `settings.refresh` | secondes entre deux rafraîchissements |

Toutes les autres clés sont les **options du type**, validées par lui.

## Types de carte

### `service`

Lien vers un service, avec l'état de son container et sa réponse HTTP.

| Option | Obligatoire | Rôle |
|---|---|---|
| `service` | oui | identifiant dans `services` |

L'état du container vient de Beszel (si `container` et la machine ont un
système Beszel), la réponse HTTP de `check`, sinon de `url`.

État affiché : *En ligne*, *Démarre*, *Redémarre*, *En pause*, *En difficulté*
(healthcheck en échec), *Arrêté* (absent de Beszel : arrêté ou supprimé),
*Ne répond pas* (sonde HTTP sans réponse ou en 5xx), *Non surveillé* (ni
container ni adresse).

### `services`

Lanceur : plusieurs services du catalogue en une grille d'icônes, chacune avec
une pastille d'état. Remplace une série de cartes `service`.

```yaml
- type: services
  title: Outils d'admin          # optionnel, « Services » par défaut
  services: [dagu, healthchecks, pihole, wiki]
  span: 2
  rows: 2
```

| Option | Obligatoire | Rôle |
|---|---|---|
| `services` | oui | identifiants dans `services`, au moins un, dans l'ordre d'affichage |

Même état que la carte `service` (Beszel pour le container, sonde HTTP sur
`check` sinon `url`). Les sondes partent en parallèle et Beszel n'est lu qu'une
fois par machine. Un service *Ne répond pas*, *Arrêté* ou *En difficulté* passe
en tête avec un nom en rouge ; *Démarre*, *Redémarre* ou *En pause* juste
après ; l'en-tête compte les problèmes. L'état exact s'affiche au survol. Un
service sans `url` est affiché sans lien.

Taille : les icônes sont réparties sur autant de lignes que la carte a de
rangées (`rows: 2` et 6 services → 2 lignes de 3), espacées pour remplir la
carte. Chaque icône garde au moins 4,5rem de large : sur écran étroit, la grille
repasse à la ligne, et les icônes qui ne tiennent plus dans la hauteur sont
coupées, d'où l'ordre « problèmes d'abord ».

### `jellyfin-latest`

Derniers ajouts d'une médiathèque Jellyfin, en affiches cliquables (fiche du
film ou de la série dans Jellyfin). Les épisodes d'une même série sont
regroupés, comme sur l'accueil de Jellyfin. Prévoir `span: 2` et `rows: 2` au
moins ; `rows: 3` laisse de la place à plusieurs lectures en cours.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` |
| `user` | obligatoire | utilisateur Jellyfin (casse ignorée) : on voit ses bibliothèques |
| `limit` | `12` | nombre d'ajouts demandés (1 à 30) |
| `types` | `[Movie, Episode]` | parmi `Movie`, `Episode`, `Series`, `MusicAlbum`, `Audio`, `Book` |
| `now_playing` | `true` | afficher les lectures en cours (tous les utilisateurs), en tête de carte |
| `now_playing_refresh` | `10` | secondes entre deux lectures des lectures en cours (≥ 5) ; le reste suit `refresh` |

L'API est appelée sur `check` du service (adresse interne), les affiches et les
liens utilisent `url`. Un `refresh` de 300 s suffit. Clé API : `JELLYFIN_API_KEY`.

Les affiches défilent (`limit` ajouts) : flèches ‹ ›, glisser à la souris,
doigt ou trackpad ; un glissement n'ouvre pas l'affiche.

Lectures en cours (une par rangée de la carte au plus, puis « +N autres
lectures », noms au survol) : affiche, titre, utilisateur et appareil,
progression, pause, badge *Transcodage*. Rafraîchies à part, toutes les
`now_playing_refresh` secondes.

Un ajout n'apparaît qu'une fois **analysé par Jellyfin** : sa tâche « Analyser
la médiathèque » tourne toutes les 12 h par défaut, et la détection en temps
réel ne voit pas les fichiers arrivés par NFS. Raccourcir l'intervalle dans
Jellyfin (Tableau de bord → Tâches planifiées) si besoin.

```yaml
- type: jellyfin-latest
  service: jellyfin
  user: tom
  span: 2
  rows: 2
  refresh: 300
```

### `qbittorrent`

Deux tuiles, *Reçu* (↓) et *Envoyé* (↑) : total depuis l'installation, débit
actuel et sa courbe sur les dernières minutes. Dessous, une barre partage reçu
et envoyé avec le ratio, puis les derniers téléchargements (icône et libellé
d'état, taille, date, barre de progression s'il est en cours). Les lignes qui
ne tiennent pas entières sont masquées : `span: 2, rows: 2` en montre 3 à 4.

qBittorrent ne donne que le débit du moment : la courbe est faite des mesures
reçues par le navigateur (20 points, soit ~10 min avec `refresh: 30`). Elle
repart de zéro quand on recharge la page.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` |
| `limit` | `5` | nombre de téléchargements (1 à 20) |
| `sort` | `added` | `added` : derniers ajoutés ; `completed` : derniers terminés |

L'API est appelée sur `check`, sinon sur `url` ; le lien « Ouvrir » utilise
`url`. **Attention à `WebUI\ServerDomains`** : qBittorrent n'accepte que les
noms qui y figurent (par exemple `qbittorrent.example`) et répond 401 à tout le
reste, adresse IP comprise, avant même de vérifier le mot de passe. Viser donc
`https://qbittorrent.example`, ou ajouter l'adresse utilisée à `ServerDomains`.

qBittorrent n'a qu'un compte WebUI, avec tous les droits : le dashboard ne fait
que lire. Il **bannit une adresse après 5 connexions refusées** : après un
refus, le dashboard attend 10 minutes avant de retenter.

### `c411`

Profil du compte sur le tracker C411 (`GET /api/user/profile`) : niveau sur le
badge du site (emblème bleu et cadran silver, images publiques de c411.org),
expérience dans une barre (XP du niveau, XP restante, total), classe avec son emblème (bronze,
argent, or, niveaux I à IV), quêtes et étoiles, une barre par voie (Seed,
Upload, Spécialisation, Compétitivité, étoile si obtenue), puis Zen, volumes
envoyé et reçu, ratio. Badges *Freeleech* et *Téléchargement bloqué* dans
l'en-tête quand ils s'appliquent. Prévoir `span: 2, rows: 2`.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` (`https://c411.org`) |

```yaml
services:
  c411: { name: C411, url: https://c411.org, icon: https://c411.org/images/logo-md.png }   # logo affiché en titre
# …
- { type: c411, service: c411, span: 2, rows: 2, refresh: 60 }
```

- **Clé** : `C411_API_SECRET`, créée avec le scope *Profil (lecture)* coché (il
  ne s'ajoute pas à une clé existante).
- **Limites** : 30 requêtes/min par clé, partagées avec Torznab, et des données
  rafraîchies au plus toutes les 30 s. Le client garde le profil 30 s quoi
  qu'il arrive, et suspend les appels une minute après un 429. `refresh: 60`
  suffit.
- L'API ne donne que l'identifiant de la classe (`tanker`,
  `team_resident_mainstream`) : la carte l'affiche mis en forme (« Tanker »).
- Volumes : `uploaded.total` = envoyé + crédit, `downloaded.total` = reçu − crédit.

### `machine`

Une machine suivie par Beszel : nom, IP, état (*En ligne*, *Hors ligne*, *En
pause*, *En attente*), température, temps depuis le dernier démarrage ; une
jauge par disque (vert, ambre dès 75 %, rouge dès 90 %), puis les débits du
disque système en deux barres verticales, lecture (↓) et écriture (↑),
moyennés sur la dernière mesure de la période ; échelle logarithmique
(1 Ko/s en bas, 1 Mo/s à mi-hauteur, 1 Go/s en haut) ; un graphique par
mesure, avec la valeur actuelle. Survoler un graphique (ou le parcourir au
clavier : Tab puis flèches) donne l'heure et la valeur de chaque série.

| Option | Défaut | Rôle |
|---|---|---|
| `host` | obligatoire | machine de `hosts`, avec un système Beszel |
| `period` | `24h` | `1h`, `12h`, `24h`, `7d`, `30d` (résolutions 1, 10, 20, 120 et 480 min de Beszel) |
| `charts` | `[cpu, memory, network]` | parmi `cpu`, `memory`, `network`, `temperature` |
| `disk_names` | `{}` | noms lisibles des disques supplémentaires : `{ sdb1: RAID }` |
| `hide_disks` | `[]` | disques supplémentaires à ne pas afficher (nom Beszel), par exemple un volume montré par une carte `storage` : `[NAS]` |

```yaml
- { type: machine, host: beta, span: 2, rows: 2, disk_names: { sdb1: RAID } }
```

Tailles comme dans Beszel (Go binaires). Réseau : envoyé (violet) et reçu
(bleu), en octets/s. Température : le capteur le plus chaud. Une machine hors
ligne garde ses dernières valeurs connues.

### `storage`

Remplissage d'un volume suivi par Beszel (un des `EXTRA_FILESYSTEMS` de
l'agent) : jauge (mêmes couleurs que `machine`), utilisé / total, place libre,
rythme de croissance (« +150 Go/mois ») et délai estimé avant qu'il soit
plein. En option : état du pool, une ligne par disque et le dernier scrub.
Le rythme est la pente de la
droite des moindres carrés sur les mesures de la période ; il n'apparaît
qu'après 2 jours de mesures, et le délai seulement si le volume grossit.
Les valeurs actuelles viennent de la dernière minute ; le rythme
ignore les tranches dont le total s'écarte de plus de 1 % du total actuel
(tranche partielle, voir `depannage.md`, ou disque ajouté : la tendance
repart alors de zéro).

| Option | Défaut | Rôle |
|---|---|---|
| `host` | obligatoire | machine de `hosts`, avec un système Beszel |
| `filesystem` | obligatoire | nom du volume dans Beszel |
| `period` | `30d` | `7d` ou `30d` (résolutions 120 et 480 min de Beszel) |
| `pool` | aucun | étiquette d'un pool btrfs ou ZFS : pastille *Sain* (ONLINE), *Dégradé* (DEGRADED, un disque manque) ou *État inconnu* (pool non vu par Beszel) |
| `disks` | `[]` | numéros de série des disques à lister, dans l'ordre (SMART suivi par Beszel, `SMART_DEVICES` de l'agent) |
| `healthchecks` | aucun | service Healthchecks du catalogue, avec `scrub_check` |
| `scrub_check` | aucun | slug du check qui reçoit les pings du scrub |

```yaml
- type: storage
  host: beta
  filesystem: NAS
  pool: tank
  title: NAS
  span: 2
  disks: [SN-0001, SN-0002]
  healthchecks: healthchecks
  scrub_check: nas-scrub
```

Chaque disque : capacité, modèle, température, âge (heures de
fonctionnement ; masqué sur carte étroite), détail SMART au survol. Pastille
orange si un compteur réalloués / en attente / illisibles / erreurs CRC n'est
pas nul ou au-delà de 50 °C ; rouge si SMART en échec ou disque absent de
Beszel (débranché, ou numéro de série mal recopié). Quatre disques tiennent sur
une rangée. Le scrub (« Scrub 12 nov. · OK ») vient de l'état du check :
OK, en retard, échec, en cours ; Healthchecks injoignable n'empêche pas
l'affichage du reste (« Scrub : ? »).

Le nom est celui que montre Beszel : par défaut le périphérique
(`sdb1`, `ata-…`), qui change avec le disque. Pour un nom stable, le donner
à l'agent : `EXTRA_FILESYSTEMS=/mnt/raid__NAS`. Un nom inconnu affiche
sur la carte la liste des volumes suivis. Sur un pool btrfs raid1, Beszel
donne la capacité utile (après duplication), pas la somme des disques.

### `ultracc`

Abonnement à une seedbox Ultra.cc : deux jauges côte à côte, faites pour
`span: 2` sur une rangée. *Stockage* : utilisé / total et place libre (ambre
dès 85 %, rouge dès 95 %). *Trafic du mois* : pourcentage consommé, volume si
`traffic_quota` est donné, date de la remise à zéro et projection au rythme
actuel jusqu'à cette date (« ≈ 76 % au 6 déc. ») ; sans projection, la date de
remise à zéro. Au-delà du quota, Ultra.cc bride le débit : la jauge passe
ambre dès 85 % projetés, rouge au-delà de 100 %. Pas de projection le premier
jour après la remise à zéro (trop peu de recul).

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue dont l'`url` est sur l'hôte Ultra.cc (seuls schéma et hôte comptent) |
| `traffic_quota` | aucun | quota de trafic mensuel de l'offre, en Go (l'API ne donne qu'un pourcentage) |
| `icon` | celle du service | icône de la carte ; `ultracc.svg` = logo d'Ultra.cc livré avec le dashboard |
| `link` | `https://cp.ultra.cc/` | adresse du lien « Ouvrir Ultra.cc → » |

```yaml
- { type: ultracc, service: seedbox, traffic_quota: 2000, icon: ultracc.svg, span: 2, refresh: 900 }
```

La source est le script non officiel « Ultra-API », à installer en SSH sur la
seedbox (`bash <(wget -qO- https://scripts.ultra.cc/util-v2/Ultra-API/main.sh)`,
choix 1 ; le choix 4 réaffiche le jeton). La carte lit
`https://<hôte>/ultra-api/total-stats` — avec un tiret, la doc d'Ultra.cc
écrit `total_stats`, qui répond 404. Le stockage est donné en Gio (`quota
-s`), le trafic seulement en pourcentage. La carte affiche des Go décimaux,
comme le panneau d'Ultra.cc : 932 Gio de l'API = « 1001 GB » du panneau.

### `github-prs`

Pull requests ouvertes d'un ou plusieurs dépôts GitHub, les plus récemment
mises à jour d'abord, chacune dans un bloc cliquable vers la PR : icône,
paquet, version cible, numéro, âge, étiquettes, mention *Brouillon*. Le dépôt
n'est affiché que si la carte en suit plusieurs.

- **Titres Renovate décodés** (« chore(deps): Update ghcr.io/x/y Docker tag to
  v1.2 ») : paquet sans registre ni préfixe, version en pastille, icône selfh.st
  devinée d'après le paquet (initiale si elle n'existe pas). Une mise à jour
  groupée (« services applicatifs ») a une icône de paquet. Les autres titres
  s'affichent tels quels.
- **Étiquettes** : leur couleur GitHub si elles en ont une ; sinon (gris par
  défaut, comme celles de Renovate) d'après leur nom : `backup`, `breaking`,
  `security`… en rouge, `revue`, `review`… en ambre, `automerge` en vert, les
  autres dans une couleur stable tirée du nom.
- **Âge** en ambre au-delà de 7 jours. L'en-tête donne le nombre total et un
lien *Tout voir* : la page des pull requests du dépôt s'il n'y en a qu'un,
sinon la même recherche sur github.com. Les PR qui ne tiennent
pas entières sont masquées.

| Option | Défaut | Rôle |
|---|---|---|
| `repos` | obligatoire | dépôts `propriétaire/nom` |
| `author` | aucun | auteur : `app/renovate` (Renovate), `renovate[bot]`, un pseudo… |
| `labels` | `[]` | étiquettes, toutes requises |
| `limit` | `8` | nombre de PR (1 à 30) |
| `api` | `https://api.github.com` | API d'une instance GitHub Enterprise |

```yaml
- type: github-prs
  title: Mises à jour en attente
  repos: [exemple/infra]
  author: app/renovate
  span: 2
  rows: 2
  refresh: 300
```

- Une seule recherche GitHub par rafraîchissement, quel que soit le nombre de
  dépôts. Limite : 30 recherches/min avec un jeton (10 sans) ; `refresh: 300` suffit.
- **Un dépôt inexistant ou inaccessible fait échouer toute la recherche**
  (422) : n'ajouter un dépôt qu'une fois créé, et accessible au jeton.
- Jeton : *fine-grained*, limité aux dépôts voulus, permissions **Pull requests**
  et **Issues** en lecture seule (la recherche passe par l'API des issues).

### `containers`

Les containers d'une machine, d'après Beszel : une ligne par container avec
une pastille d'état (vert, ambre, rouge), son nom, et soit depuis combien de
temps il tourne et sa mémoire, soit son problème (*Arrêté*, *En difficulté*,
*Redémarre*, *En pause*, *Démarre*). Problèmes en premier, puis ordre
alphabétique ; CPU et détails au survol. L'en-tête donne le nombre de
containers, de problèmes, et de containers masqués faute de place.

| Option | Défaut | Rôle |
|---|---|---|
| `host` | obligatoire | machine de `hosts`, avec un système Beszel |
| `hide` | `[]` | containers à masquer, jokers acceptés : `[beszel-agent, immich_*]` |

```yaml
- { type: containers, host: alpha, span: 2, rows: 3, hide: [beszel-agent] }
```

- Beszel ne remonte que les containers qui tournent. Ceux que le catalogue
  (`services.*.container` sur cette machine) attend et qui manquent sont
  affichés *Arrêté* ; un autre container arrêté n'apparaît pas.
- Mémoire à 0 = non mesurée (voir `depannage.md`) : elle n'est pas affichée.

### `backblaze`

Stockage Backblaze B2 : **coût mensuel estimé** (et annuel), volume stocké,
nombre de fichiers, date du dernier envoi (en ambre au-delà de `stale_hours` :
une copie de nuit manquée). Avec un seul bucket, les chiffres tiennent sur une
rangée (`span: 2, rows: 1`) ; avec plusieurs, une ligne par bucket. À partir de
`rows: 2`, un graphique montre l'**évolution du coût mensuel** (date et tarif
du jour au survol).

| Option | Défaut | Rôle |
|---|---|---|
| `buckets` | `[]` | buckets à afficher ; vide = tous ceux que la clé voit |
| `price_per_tb` | `{ 2023-10-03: 6, 2026-10-01: 6.95 }` | tarif du stockage par To (décimal) et par mois : un nombre, ou des tarifs datés `{ AAAA-MM-JJ: tarif }`, chacun en vigueur à partir de sa date |
| `free_gb` | `10` | Go offerts |
| `currency` | `$` | devise affichée |
| `vat` | `0` | taux de TVA (%) que Backblaze ajoute à la facture, 20 en France ; le montant affiché est alors TTC (hors taxes au survol) |
| `stale_hours` | `36` | âge au-delà duquel le dernier envoi est signalé |
| `history_days` | `365` | jours d'historique du coût affichés |

```yaml
- { type: backblaze, span: 2, rows: 2, refresh: 86400 }   # une fois par jour
```

- **Le coût est une estimation** : B2 n'expose pas sa facturation par API (les
  rapports d'usage sont réservés aux comptes partenaires). Calcul : (Go stockés
  − Go offerts) / 1000 × `price_per_tb`. Tarif officiel 6,95 $/To depuis le
  2026-10-01 ; s'il change, **ajouter** une date dans `price_per_tb` plutôt que
  remplacer la valeur : l'historique garde ainsi le tarif de chaque jour. Les
  téléchargements sont gratuits jusqu'à 3× le volume stocké.
- **Historique** : ni le coût ni la taille passés ne sont disponibles chez B2
  (le `prune` de restic efface les anciennes données). La carte relève donc la
  taille de chaque bucket au plus une fois par jour (à chaque lecture de B2, soit
  `refresh`) dans `HISTORY_DATA_DIR/backblaze.json`, et recalcule le coût de
  chaque jour au tarif de ce jour (TVA et Go offerts actuels). L'historique ne
  commence qu'au premier relevé ; un jour sans visite du dashboard n'a pas de
  relevé.
- **Taille** : B2 n'a pas non plus de taille de bucket ; elle est recalculée en
  listant toutes les versions de fichiers (ce qui est facturé), 1 appel par
  10 000 fichiers (classe C : 2 500 gratuits par jour).
- **Clé** : *Application Keys* → *Add*, type *Read Only*, limitée au bucket si
  on veut. Ne pas réutiliser la clé de restic (écriture et suppression).

### `mealie`

Recettes Mealie : **idée recette du jour** (tirée au sort parmi toutes les
recettes, la même jusqu'à minuit), **au menu** (planning des repas, s'il est
rempli : 3 entrées au plus) et les recettes en slider, les plus récentes
d'abord. Photos publiques, chargées directement.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` |
| `limit` | `12` | recettes dans le slider (1 à 50) |
| `plan_days` | `7` | jours de planning à partir d'aujourd'hui ; `0` = sans |

```yaml
- { type: mealie, service: mealie, span: 2, rows: 2, refresh: 3600 }
```

### `audiobookshelf`

Livres audio : **reprendre l'écoute** (livres commencés et pas finis, le plus
récemment écouté d'abord, avec progression et temps restant ; une ligne par
rangée de la carte, puis « +N autres ») et les derniers ajouts en slider
(bibliothèques de livres seulement, pas les podcasts). Couvertures publiques,
chargées directement.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` (`check` sert d'adresse d'API s'il est donné) |
| `user` | propriétaire de la clé | utilisateur Audiobookshelf dont on suit l'écoute (clé d'un admin requise) |
| `limit` | `12` | livres dans le slider (1 à 50) |

```yaml
- { type: audiobookshelf, service: audiobooks, user: hugo, span: 2, rows: 2, refresh: 600 }
```

- **Clé** : *Paramètres → API Keys*, liée à un compte. Une clé d'un compte non
  admin ne voit que ses propres écoutes (pas d'option `user`).

### `immich`

Photos Immich du propriétaire de la clé : **souvenirs « ce jour-là »** (les
mêmes que dans l'application, 3 au plus, « Il y a N ans ») et les dernières
photos et vidéos de la frise en slider (ni archivées ni verrouillées), avec le
nombre total de photos et de vidéos.

Les miniatures exigent la clé : le navigateur ne les demande pas à Immich mais
au dashboard (`/api/cards/{id}/image`, adresse signée), qui les relaie et les
garde 24 h. La clé ne quitte jamais le serveur.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` (`check` sert d'adresse d'API s'il est donné) |
| `limit` | `20` | photos dans le slider (1 à 100) |
| `memories` | `true` | souvenirs au-dessus du slider |

```yaml
- { type: immich, service: immich, span: 2, rows: 2, refresh: 900 }
```

- **Clé** : *Paramètres du compte → Clés d'API → Nouvelle clé*, en ne cochant
  que les 4 permissions ci-dessus. Une clé par personne pour avoir ses photos.

### `calibre-web`

Derniers ebooks ajoutés à Calibre-Web ou Calibre-Web Automated, en slider au
format livre, avec un badge « Nouveau » la première semaine. Calibre-Web n'a
pas d'API JSON : la carte lit le **flux OPDS** (`/opds/new`) en Basic auth.
Les couvertures exigent l'authentification : elles passent par le relais
d'images signé (comme `immich`) et sont gardées 24 h.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` (`check` sert d'adresse d'API s'il est donné) |
| `limit` | `20` | livres dans le slider (1 à 50) |

```yaml
- { type: calibre-web, service: ebooks, span: 2, rows: 2, refresh: 1800 }
```

- **Compte** : un utilisateur Calibre-Web dédié, sans droit d'envoi, de
  modification ni d'administration. Le catalogue OPDS doit être
  activé dans la configuration de Calibre-Web (c'est le cas par défaut).
- Lien d'un livre : `{url}/book/{id}`, qui demande d'être connecté à Calibre-Web.

### `backups`

État des sauvegardes, d'après le statut publié chaque matin par la sentinelle
du homelab (rôle Ansible `backup-sentinel`). La sentinelle l'envoie comme
rapport de son ping à Healthchecks : la carte lit le rapport de son dernier
passage. Même présentation que `backblaze`, une rangée suffit :

- **Sauvegardes** : « 19/19 à jour » et le nombre de nuits sans incident (champ
  `history` de la sentinelle), ou « N à surveiller » avec leurs noms. La liste
  complète, avec l'âge de chaque sauvegarde et la raison d'un défaut, s'affiche
  au survol.
- **Dernière sauvegarde** : le dernier passage de restic (champ `restic`, joint
  par la sentinelle). Affiche la durée totale, le volume ajouté au dépôt et la
  durée de la copie vers B2 ; le détail (fichiers, volume analysé) apparaît au
  survol. En cas d'échec : l'étape en cause et son code. « — » si la sentinelle
  ne joint pas ce statut.

Les âges sont recalculés à l'affichage. Si la sentinelle n'est pas repassée
depuis `stale_hours`, l'en-tête l'indique (« sentinelle en retard ») : l'état
affiché est ancien.

| Option | Défaut | Rôle |
|---|---|---|
| `healthchecks` | obligatoire | service Healthchecks du catalogue (`check` sert d'adresse pour l'API s'il est donné) ; clé dans `HEALTHCHECKS_API_KEY` |
| `check` | `sentinel` | slug du check de la sentinelle |
| `aliases` | `{}` | nom dans la sentinelle → service du catalogue, quand ils diffèrent (nom affiché) ; sinon le nom de la sentinelle, avec une majuscule |
| `stale_hours` | `26` | au-delà, la sentinelle est signalée en retard (elle passe une fois par jour) |

```yaml
- type: backups
  healthchecks: healthchecks
  aliases: { bookstack: wiki, cwa: ebooks, audiobookshelf: audiobooks }
  span: 2
  rows: 1
  refresh: 600
```

### `doc-fetcher`

Imports automatiques de documents dans Paperless par doc-fetcher (rôle Ansible
`doc-fetcher`). À chaque passage, le script envoie son rapport comme contenu de
son ping de fin à Healthchecks ; la carte lit les `runs` derniers :

- **En-tête** : âge du dernier passage. Si le passage automatique (cron) n'est
  pas revenu depuis `stale_days`, « passage automatique en retard », même si le
  script a été lancé à la main entre-temps.
- **Sites** : le dernier état connu de chaque site (un passage `--site` ne
  touche que le sien) : nombre de documents sur le site, « code à saisir »
  (double authentification à faire au terminal) ou « échec ». Les erreurs
  s'affichent au survol ; celle d'un site bloqué aussi en clair sous la liste.
- **Historique** : une pastille par passage lu (vert, ambre, rouge ; atténuée
  si lancé à la main), détail au survol.
- **Derniers documents importés** : titre, site, date du document, avec un lien
  vers Paperless si `service` est donné.
- **Pied** : la planification, si le script la connaît.

| Option | Défaut | Rôle |
|---|---|---|
| `healthchecks` | obligatoire | service Healthchecks du catalogue (`check` sert d'adresse pour l'API s'il est donné) ; clé dans `HEALTHCHECKS_API_KEY` |
| `check` | `doc-fetcher` | slug du check de doc-fetcher |
| `runs` | `12` | nombre de passages lus (historique) ; Healthchecks garde les 100 derniers pings d'un check, soit 50 passages |
| `service` | `null` | service Paperless du catalogue, pour les liens vers les documents |
| `labels` | `{}` | nom du site → nom affiché ; sinon le nom avec une majuscule |
| `stale_days` | `8` | au-delà, le passage automatique est signalé en retard |
| `documents` | `6` | nombre de documents listés |

```yaml
- type: doc-fetcher
  healthchecks: healthchecks
  service: paperless
  labels: { impots: Impôts }
  span: 2
  rows: 2
  refresh: 3600
```

Format d'un rapport (`state` vaut `ok`, `failed` ou `action`). Le passage seul,
sans enveloppe ni `schedule`, est aussi accepté :

```json
{
  "job": "doc-fetcher",
  "schedule": "chaque lundi à 7 h 30",
  "run": {
    "started_at": "2026-10-19T07:30:01+02:00",
    "finished_at": "2026-10-19T07:31:12+02:00",
    "state": "ok",
    "manual": false,
    "sites": [
      {
        "name": "paie", "state": "ok", "found": 12, "missing": 1,
        "imported": [{ "id": 412, "title": "Bulletin septembre 2026", "created": "2026-09-30" }],
        "errors": []
      }
    ]
  }
}
```

### `dagu`

Tâches planifiées du homelab, lues dans [Dagu](https://github.com/dagucloud/dagu)
(rôle Ansible `service-dagu`). Seules les tâches qui portent le label `label`
s'affichent ; côté Ansible, c'est le label `dashboard` de `dagu_jobs`.

- **En-tête** : nombre de tâches dont le dernier passage a échoué, et lien
  « Ouvrir Dagu ».
- **Une ligne par tâche** : état du dernier passage (réussie, en partie : une
  étape en échec et les autres faites, échec, en cours, jamais lancée,
  suspendue), quand (« il y a 5 h », « depuis 2 h » s'il tourne encore, « à la
  main »), planning en clair et prochain passage, et une pastille par passage
  récent (vert, ambre, rouge, bleu en cours ; atténuée si lancé à la main).
  La description de la tâche s'affiche au survol.
- **Clic sur une tâche** : sa page dans Dagu (journaux de chaque étape, bouton
  de relance). La carte ne lance rien elle-même.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service Dagu du catalogue (`check` sert d'adresse pour l'API s'il est donné) ; clé dans `DAGU_API_KEY`, en rôle viewer (lecture seule) |
| `label` | `dashboard` | seules les tâches qui portent ce label s'affichent |
| `runs` | `10` | nombre de passages lus par tâche (historique) |
| `titles` | `{}` | nom de la tâche → nom affiché ; sinon le nom avec une majuscule |

```yaml
- type: dagu
  service: dagu
  titles: { backup-nuit: Sauvegardes de la nuit, doc-fetcher: Import des documents }
  span: 2
  rows: 2
  refresh: 60
```

### `bricktracker`

Collection Lego de BrickTracker : nombre de sets, de pièces et de minifigs,
pièces manquantes ou abîmées à retrouver (pastille, lien vers la page
*Problems*), et les derniers sets ajoutés en slider. Les photos des sets
sont publiques ; elles sont affichées entières sur fond blanc.

BrickTracker n'a pas d'API de lecture : la carte lit la page d'accueil (derniers
sets) et la page *Statistics*. Elles doivent rester accessibles sans
connexion. Si BrickTracker change de mise en page, la carte affiche une erreur
qui le dit, plutôt que des chiffres faux.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` (`check` sert d'adresse pour lire les pages s'il est donné) |

```yaml
- { type: bricktracker, service: bricktracker, span: 2, rows: 2, refresh: 3600 }
```

### `weather`

Météo d'[Open-Meteo](https://open-meteo.com), gratuite, sans clé (usage non
commercial). La carte montre :
- **maintenant** : icône, température, libellé, ressenti et vent ;
- les **prochaines heures**, heure par heure ;
- les **prochains jours**, avec max / min ;
- la **probabilité de pluie**, en bleu à partir de 40 % ;
- le lever et le coucher du soleil.

Les icônes sont des emoji : colorés, lisibles par les enfants, aucun fichier à
charger. Leur dessin dépend du système.

| Option | Défaut | Rôle |
|---|---|---|
| `latitude`, `longitude` | obligatoires (sauf `location: profile`) | le lieu, en degrés décimaux |
| `place` | `null` | nom affiché en titre (sinon « Météo ») |
| `location` | `null` | `profile` = chaque personne choisit son lieu dans « Ma config » ; le lieu du YAML sert alors de lieu par défaut |
| `days` | `5` | jours de prévision, aujourd'hui compris (1 à 7) |
| `hours` | `12` | heures de prévision, heure en cours comprise (2 à 24) |
| `timezone` | `Europe/Paris` | fuseau des heures affichées |
| `api` | `https://api.open-meteo.com` | adresse de l'API (tests, instance auto-hébergée) |

```yaml
- { type: weather, place: Maison, latitude: 48.85, longitude: 2.35, span: 2, rows: 2, refresh: 1800 }
```

Le lieu reste dans le YAML, donc dans le rôle Ansible : jamais dans l'image
ni dans ce dépôt.

**Lieu choisi par chaque personne** (`location: profile`) : une section
« Météo » apparaît dans « Ma config » du profil. La personne cherche sa ville
(géocodage d'Open-Meteo, par le serveur : `GET /api/places?q=`) ou saisit des
coordonnées. Le lieu est arrondi à 0,01° (environ 1 km) et rangé dans
`<profil>.settings.json` de `PROFILE_DATA_DIR`. « Revenir au lieu par défaut »
reprend celui du YAML. Sans lieu dans le YAML ni choisi, la carte affiche
« Pas encore de lieu pour ce profil : le choisir dans « Ma config ». ». Un seul
lieu par profil : toutes ses cartes météo en `location: profile` le partagent.

```yaml
- { type: weather, place: Maison, latitude: 48.85, longitude: 2.35, location: profile, span: 2, rows: 2, refresh: 1800 }
```

### `music-assistant`

Music Assistant (2.10) : ce que jouent les enceintes et les dernières écoutes.

- **En cours de lecture** (flux `speakers`, toutes les `speakers_refresh` s) :
  une ligne par enceinte qui joue ou en pause. Elle montre la pochette, le
  titre, l'artiste, le nom de l'enceinte et la progression. La ligne est
  teintée avec la couleur de la pochette calculée par Music Assistant ; si
  cette couleur est trop sombre ou trop claire pour se voir dans les deux
  thèmes, la carte prend sa couleur par défaut (indigo).
  Les enceintes à l'arrêt tiennent sur une ligne « En veille : … ». Les
  enceintes indisponibles n'apparaissent pas. Les lecteurs que Music
  Assistant masque, comme le lecteur web du navigateur (« Web (Firefox on
  Linux) »), n'apparaissent que lorsqu'ils jouent ou sont en pause.
- Position : celle du titre (`current_media.elapsed_time`), pas celle du
  lecteur, qui se cumule sur toute la file (constaté en 2.10.6).
- **Écoutés récemment** : un slider des derniers titres, albums et artistes.

Les pochettes passent par `/imageproxy/<id>` de Music Assistant, accessible
sans jeton. La carte ne transmet jamais le chemin d'origine d'une image : il
peut contenir la clé d'un fournisseur (Jellyfin : `?api_key=…`).

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` (`check` sert d'adresse d'API s'il est donné) |
| `limit` | `12` | écoutes récentes dans le slider (1 à 50) |
| `speakers` | `[]` | noms des enceintes à montrer, tels que dans Music Assistant ; vide = toutes |
| `speakers_refresh` | `10` | secondes entre deux lectures de l'état des enceintes (5 au moins) |

```yaml
- { type: music-assistant, service: music, span: 2, rows: 2, refresh: 300 }
- { type: music-assistant, service: music, title: Ma musique, speakers: [Chambre Nina] }
```

- **Jeton** : Music Assistant 2.10 exige une authentification. Créer un
  utilisateur dédié au rôle **Invité** (lecture de la bibliothèque et des
  lecteurs, le plus restreint qui existe), puis un **jeton longue durée**
  (valable 1 an) depuis son profil, dans `MUSIC_ASSISTANT_TOKEN`. Penser à le
  renouveler : un jeton expiré donne « Jeton Music Assistant refusé (401) ».
- Les écoutes récentes sont celles de l'utilisateur du jeton.

### `jelu`

Carnet de lecture Jelu de la famille :
- **en-tête** : nombre de livres terminés dans l'année, par lecteur ;
- **En ce moment** : qui lit quoi, avec la couverture, l'auteur et l'avancement
  s'il est saisi (0 % est la valeur par défaut de Jelu : pas de barre). Une ligne par rangée de la carte, puis « +N » ;
- **Terminés en <année>** : slider des couvertures, le lecteur en sous-titre.

Un seul jeton suffit pour toute la famille. Les événements de lecture de Jelu
couvrent tous les utilisateurs et donnent le lecteur de chacun : pas besoin de
connaître les identifiants. Du lecteur, la carte ne garde que le nom de
connexion. Le DTO utilisateur de Jelu porte aussi un champ `password`, et les
notes personnelles ne sont jamais transmises. Les couvertures sont servies sans
connexion par Jelu (`/files/<image>`).

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` (`check` sert d'adresse d'API s'il est donné) |
| `readers` | `{}` | nom de connexion Jelu (casse indifférente) → prénom affiché ; limite la carte à ces lecteurs, dans cet ordre, même à 0 livre. Vide = tous, par nom de connexion |
| `limit` | `12` | livres terminés dans le slider (1 à 50) |

```yaml
- { type: jelu, service: jelu, readers: { tom: Tom, lea: Lea }, span: 2, rows: 2, refresh: 900 }
```

- **Jeton** : dans Jelu, *Profil → API tokens*. Ne cocher que `reading:read` :
  la carte ne lit que `/api/v1/reading-events`.

### `crafty`

Serveurs Minecraft de Crafty Controller (4.11) : une ligne par serveur avec
- son icône, ou un bloc d'herbe par défaut ;
- son nom, et un badge « Internet » s'il est ouvert sur Internet ;
- son état (en ligne, démarrage, planté, arrêté) ;
- sous le nom : les joueurs connectés sur le maximum, avec leurs pseudos, pour
  un serveur en ligne ; sinon la version et la taille du monde. La version d'un
  serveur en ligne apparaît au survol.

Les serveurs arrêtés sont grisés. En tête de carte, le nombre total de joueurs
en ligne, masqué si la carte n'a qu'une colonne de large. La carte tient sur
1 colonne (`span: 1`). Sans `servers`, les serveurs en ligne passent d'abord, puis les
plantés, puis les arrêtés.

Crafty interroge lui-même ses serveurs toutes les quelques secondes ; la carte
lit son dernier relevé (une requête par serveur). De la liste des serveurs,
elle ne garde que nom, port et identifiant : Crafty y renvoie aussi les chemins
et la commande de lancement.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` (`check` sert d'adresse d'API s'il est donné) |
| `servers` | `[]` | noms des serveurs à montrer, dans cet ordre ; vide = tous |
| `public` | `[]` | noms des serveurs ouverts sur Internet (badge « Internet ») |

```yaml
- { type: crafty, service: crafty, public: [Public], span: 2, rows: 2, refresh: 60 }
- { type: crafty, service: crafty, title: Minecraft, servers: [Monde survie, Monde créatif] }
```

- **Clé** : une clé sans accès complet ne voit que les serveurs des **rôles**
  de son utilisateur, même s'il est super-utilisateur. Utiliser un utilisateur
  dédié, non super-utilisateur, dont le rôle donne accès aux serveurs voulus
  (aucune permission à cocher), et lui créer une clé sans accès complet.

### `minecraft`

Un serveur Minecraft en image : un **rendu du monde** (PNG, produit à part),
avec par-dessus le nom du serveur, son état et les joueurs connectés (via
Crafty, comme la carte `crafty`). La date du rendu vient de l'en-tête
`Last-Modified` de l'image. L'image s'ouvre en grand au clic.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service Crafty du catalogue (clé `CRAFTY_API_TOKEN`) |
| `server` | obligatoire | nom du serveur dans Crafty |
| `image` | obligatoire | adresse du rendu, lisible par le navigateur et par le serveur du dashboard |

```yaml
- type: minecraft
  service: crafty
  server: Mon monde
  image: https://images.example/minecraft/mon-monde.png
  span: 2
  rows: 3
```

**Produire l'image** : le dashboard ne rend pas le monde lui-même. Un rendu
isométrique se fait avec [mcmap](https://github.com/spoutn1k/mcmap), il lit les
mondes 26.x. Il est lancé à la main, serveur arrêté, et la sortie est rognée
avec ImageMagick :

```bash
mcmap -from X1 Z1 -to X2 Z2 -min 58 -shading -ne -padding 2 -file monde.png <dossier du monde>
convert monde.png -trim +repage -bordercolor none -border 4 monde.png
```

- `-min 58` coupe le sous-sol. Sans ça, mcmap dessine tout jusqu'à la bedrock.
- `-ne` (ou `-nw`, `-se`, `-sw`) choisit l'angle de vue.
- La zone est en coordonnées de blocs ; 320 × 320 blocs donnent une image d'environ 1 300 pixels de large.

Pour une vue de dessus, uNmINeD convient aussi. Sur un monde 26.x, il faut
forcer `--dimension=0` : par défaut, il lit le Nether.

### `gw2`

Compte Guild Wars 2, via l'API officielle (`api.guildwars2.com/v2`), en
français quand la route le permet :
- **en-tête** : nom du compte, niveau de fractales, rang McM, et l'or à
  récupérer au comptoir s'il y en a ;
- **portefeuille** : l'or en pièces d'or, d'argent et de cuivre, puis les autres
  monnaies choisies, avec les icônes du jeu ;
- **Chambre forte du Sorcier** : avancement du quotidien et de l'hebdomadaire,
  temps restant avant la réinitialisation (chaque jour à 0 h UTC, le lundi à
  7 h 30 UTC), puis les objectifs restants avec leur progression et leurs
  acclamations astrales ;
- **artisanat quotidien** : matériaux raffinés aujourd'hui sur le total possible.

| Option | Défaut | Rôle |
|---|---|---|
| `currencies` | `[1, 63, 2, 4]` | monnaies affichées, identifiants de `/v2/currencies` : or, acclamations astrales, karma, gemmes |
| `credentials` | `null` | `profile` = clé de « Ma config » ; un nom = `GW2_API_KEY_<NOM>` ; absent = `GW2_API_KEY` (voir « Identifiants par personne ») |
| `api` | `https://api.guildwars2.com` | adresse de l'API (tests) |

```yaml
- { type: gw2, span: 2, rows: 2, refresh: 300 }                         # GW2_API_KEY
- { type: gw2, credentials: profile, span: 2, rows: 2, refresh: 300 }    # clé de « Ma config »
```

- **Clé** : https://account.arena.net/applications. Les clés sont en lecture
  seule par nature ; cocher au moins `account`, `wallet`, `progression` et
  `tradingpost`.
- Environ 9 requêtes par rafraîchissement, loin de la limite de l'API.
- Les horaires des boss du monde ne sont pas dans l'API (seulement ceux déjà
  faits) : pas encore affichés.

### `romm`

Bibliothèque de jeux [RomM](https://romm.app) : derniers jeux ajoutés en slider
(jaquette, plateforme, badge « Nouveau » la première semaine), nombre de jeux,
de plateformes et de sauvegardes (sauvegardes + états) dans l'en-tête, puis le
nombre de jeux par plateforme, les plus fournies d'abord. Jeu non identifié :
nom du fichier.

Les jaquettes sont publiques dans RomM (`/assets/romm/resources/…`) : le
navigateur les lit directement, sans relais.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service du catalogue, avec une `url` (`check` sert d'adresse d'API s'il est donné) |
| `limit` | `12` | jeux dans le slider (1 à 50) |
| `platforms` | `[]` | identifiants (slug) des plateformes à montrer, vide = toutes ; les sauvegardes restent celles de toute la bibliothèque |

```yaml
- { type: romm, service: romm, span: 2, rows: 2, refresh: 900 }
- { type: romm, service: romm, platforms: [acpc, amstrad-gx4000], span: 2, rows: 2 }
```

- **Jeton** (`ROMM_API_TOKEN`) : un compte RomM au rôle *Viewer*, puis
  *Profil → Client API Tokens*, scopes `roms.read` et `platforms.read`.
- Liens : `{url}/rom/{id}` et `{url}/platform/{id}`, qui demandent d'être
  connecté à RomM.

### `energy`

Énergie de la maison, lue dans Home Assistant : puissance **du moment**
(solaire, réseau, et maison ≈ leur somme), énergie et coût **du jour**, puis
un graphique au choix : puissance moyenne heure par heure sur 24 h, ou barres
empilées (réseau + solaire) des 30 derniers jours.

Compteurs d'énergie et prix du kWh viennent du **tableau Énergie** de Home
Assistant (`energy/get_prefs`) : le configurer dans HA (*Paramètres → Tableaux
de bord → Énergie*). Les statistiques long terme de HA (`recorder`) donnent
les courbes. Seuls les capteurs de puissance instantanée sont à donner ici.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service Home Assistant du catalogue, avec une `url` (`check` sert d'adresse d'API s'il est donné : `http://…:8123`) |
| `solar_power` | `null` | capteur de puissance solaire instantanée (W) : `sensor.…` |
| `grid_power` | `null` | capteur de puissance tirée du réseau (W, ou VA pour un compteur Linky) : `sensor.…` |

```yaml
- { type: energy, service: homeassistant, solar_power: sensor.panneaux_puissance, grid_power: sensor.compteur_puissance, span: 2, rows: 2, refresh: 60 }
```

- **Jeton** (`HOME_ASSISTANT_TOKEN`) : un utilisateur HA dédié, **non
  administrateur**, puis *Profil → Sécurité → Jetons d'accès longue durée*.
- Sans injection mesurée (kit solaire branché sur une prise), la part du
  solaire renvoyée au réseau est inconnue : « Maison » surestime la
  consommation quand le solaire dépasse le besoin, et l'économie affichée est
  un plafond (« ≤ »).
- Courbe à l'heure, pas aux 5 min : un compteur Linky avance par pas de
  0,1 kWh, trop grossier pour des tranches courtes. L'heure en cours est dans
  les tuiles.
- Lien « Ouvrir » : `{url}/energy`.

### `temperatures`

Température de chaque pièce, lue dans Home Assistant : valeur du moment,
humidité, courbe des 24 dernières heures (moyenne de chaque heure), et la
consigne du chauffage s'il y a un thermostat (`climate.…`) dans la pièce,
avec « chauffe » quand il chauffe. La sonde extérieure s'affiche à part, dans
l'en-tête (« Dehors »).

Les capteurs sont trouvés **par pièce** dans les registres de Home Assistant
(pièce de l'entité, sinon celle de son appareil) : rien à lister. Dans une
pièce, un thermomètre passe avant la sonde intégrée à un thermostat ; les
sondes de diagnostic (processeur…) sont ignorées. Une pièce sans sonde de
température n'apparaît pas.

| Option | Défaut | Rôle |
|---|---|---|
| `service` | obligatoire | service Home Assistant du catalogue, avec une `url` |
| `areas` | `[]` | identifiants des pièces HA à montrer, dans cet ordre ; vide = toutes, dans l'ordre de HA |
| `outdoor` | `null` | pièce HA de la sonde extérieure (ex. `jardin`), montrée dans l'en-tête |

```yaml
- { type: temperatures, service: homeassistant, outdoor: jardin, span: 2, rows: 2, refresh: 300 }
```

- Même jeton que `energy` (`HOME_ASSISTANT_TOKEN`, utilisateur non admin).
- Identifiant d'une pièce : *Paramètres → Pièces et zones*, dans l'adresse
  de la pièce (`/config/areas/area/<id>`).

<!-- Ajouter ici chaque nouveau type de carte (voir docs/ajouter-une-carte.md). -->
