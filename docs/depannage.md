# Dépannage

## Une carte affiche une erreur

Le texte en bas de la carte vient de l'une de deux sources :

| Message | Origine | Piste |
|---|---|---|
| Cartes `service` en « ne répond pas » au hasard alors que le service tourne, erreur « Idle timeout reached » ou latence ~3 s | résolution DNS lente dans le container (pas le service) : en dev, relais DNS de Docker Desktop | donner Pi-hole au container (`dns:`), voir docs/developpement.md ; vérifier : `curl -w '%{time_namelookup}'` depuis le container |
| « Beszel injoignable : … » | le hub ne répond pas | `BESZEL_URL`, Caddy, le container `beszel-hub` ; en dev, la CA (`TLS_CA_FILE`) |
| « Connexion à Beszel refusée … » | identifiants refusés | `BESZEL_EMAIL` / `BESZEL_PASSWORD` ; le compte existe-t-il ? |
| « Beszel ne connaît pas le système « X » (systèmes visibles : …) » | nom faux dans `hosts.<m>.beszel`, ou compte pas membre du système | copier le nom exact listé ; ajouter le compte au système dans Beszel |
| « Beszel ne reçoit plus les données de X » | l'agent de la machine ne répond plus | la machine, son agent (`beszel-agent`) |
| « Clé API Jellyfin absente / refusée » | `JELLYFIN_API_KEY` vide ou révoquée | créer une clé dans Jellyfin (Tableau de bord → Clés API) |
| « Utilisateur Jellyfin inconnu « x » (utilisateurs : …) » | option `user` fausse | prendre un nom de la liste |
| « Connexion à qBittorrent refusée (HTTP 401) … nouvel essai dans 10 min » | identifiants faux, **ou** adresse absente de `WebUI\ServerDomains` (une IP par exemple : 401 aussi) | corriger `QBITTORRENT_USERNAME` / `QBITTORRENT_PASSWORD` ; viser un nom listé dans `ServerDomains` ; le refus est retenu 10 min (vider le cache pour réessayer tout de suite : `bin/console cache:pool:clear cache.app`) |
| « Clé API C411 refusée (401) » / « … pas le scope « Profil (lecture) » (403) » | clé fausse, ou créée sans le scope | créer une nouvelle clé en cochant *Profil (lecture)* |
| « Limite de l'API C411 atteinte (429) » | plus de 30 requêtes/min sur la clé (Torznab compris) | rien à faire : les appels reprennent après une minute |
| « GitHub refuse la recherche : … cannot be searched … » | un des `repos` n'existe pas, ou le jeton n'y a pas accès (dépôt privé sans jeton) | vérifier l'orthographe ; donner au jeton l'accès à ce dépôt |
| « Jeton GitHub refusé (401) » / « Limite de l'API GitHub atteinte » | jeton expiré ou révoqué ; trop de recherches | recréer le jeton ; attendre la reprise indiquée |
| « Clé Backblaze refusée (401) » | clé fausse ou supprimée | recréer une clé *Read Only* dans Backblaze |
| « Jeton Mealie refusé » | jeton supprimé ou expiré | recréer un jeton (profil → API Tokens) |
| « Clé Audiobookshelf refusée (401/403) » | clé fausse ou révoquée ; `user` demandé avec une clé non admin (403) | recréer la clé ; prendre celle d'un admin pour suivre un autre utilisateur |
| « Clé Immich refusée (403), vérifier IMMICH_API_KEY et ses permissions » | permission manquante sur la clé (401 : clé fausse ou supprimée) | recréer la clé avec `asset.read`, `asset.view`, `asset.statistics`, `memory.read` |
| Photos Immich grises, carte sinon remplie | le relais d'images échoue (`/api/cards/{id}/image` en 403/502) | 403 : adresse signée périmée après changement de `APP_SECRET` (recharger la page) ; 502 : `asset.view` manquant ou Immich injoignable |
| « Identifiants Calibre-Web refusés (401) » | compte faux ou désactivé | vérifier `CWA_USERNAME` / `CWA_PASSWORD` en se connectant avec |
| « La réponse de /opds/new n'est pas un flux OPDS » | catalogue OPDS désactivé, ou page de connexion renvoyée | activer OPDS dans la configuration de Calibre-Web |
| Quelques couvertures grises, puis présentes au rechargement | Calibre-Web a mis plus de 15 s à servir la couverture (« Idle timeout » en 502 sur `/api/cards/{id}/image`) | rien à faire : l'erreur n'est pas gardée en cache, la couverture arrive au prochain affichage |
| « … n'est pas un statut de sentinelle » | `url` ne pointe pas sur `backup-status.json` (page HTML, 404 servie en 200…) | ouvrir l'adresse : il faut un JSON avec `generated_at` et `services` |
| Carte `backups` : bandeau « La sentinelle n'est pas passée depuis … » | cron de la sentinelle arrêté, ou publication de son fichier en échec | `grep sentinel` dans les logs de `backup-run` sur alpha ; relancer `/usr/local/bin/backup-freshness` |
| « « Total Sets » introuvable dans la page Statistiques de BrickTracker » | nouvelle version de BrickTracker, gabarit changé | comparer la page à `tests/fixtures/bricktracker-*.html`, adapter `BrickTrackerClient` et les fixtures |
| « BrickTracker demande une connexion pour … » | authentification activée dans BrickTracker | la carte ne lit que des pages publiques : désactiver l'authentification ou retirer la carte |
| « Open-Meteo refuse la demande : … » | coordonnées hors limites, option mal saisie | relire `latitude` / `longitude` (degrés décimaux, latitude entre -90 et 90) |
| « Jeton Music Assistant refusé (401) » | jeton faux, révoqué ou expiré (1 an pour un jeton longue durée) | recréer un jeton longue durée pour l'utilisateur du dashboard |
| Carte `music-assistant` sans aucune enceinte | enceintes indisponibles, ou noms de `speakers` mal écrits | comparer avec les noms affichés dans Music Assistant (respecter la casse) |
| « Jeton Jelu refusé (403), vérifier JELU_API_TOKEN et son scope reading:read » | jeton faux, révoqué, ou sans le scope `reading:read` | recréer le jeton dans Jelu (*Profil → API tokens*) avec `reading:read` |
| Carte `jelu` : un membre de la famille absent | son nom de connexion n'est pas dans `readers`, ou mal écrit | reprendre le nom de connexion exact (celui de la page de connexion Jelu) |
| « Clé Crafty refusée (401/403) » | clé fausse ou supprimée | recréer la clé pour l'utilisateur du dashboard |
| Carte `crafty` vide, sans erreur | clé sans accès complet, et son utilisateur n'a aucun rôle donnant accès aux serveurs | donner à l'utilisateur un rôle avec les serveurs (aucune permission nécessaire) |
| « Image introuvable (404) : … » (carte `minecraft`) | le rendu n'a pas encore été produit, ou l'adresse `image` est fausse | lancer le rendu, vérifier l'adresse dans un navigateur |
| « Serveur Crafty inconnu « x » (serveurs : …) » | faute dans l'option `server` | reprendre un nom de la liste du message |
| « Clé Guild Wars 2 refusée (403) sur /v2/… : Requires scope … » | la clé n'a pas le droit cité | recréer la clé sur account.arena.net en cochant ce droit |
| « … : Variable XXX_<NOM> absente ou vide (identifiants de la carte) » | carte avec `credentials: <nom>` sans la variable correspondante | ajouter `XXX_<NOM>=…` dans `.env.local` (dev) ou au vault (prod) |
| « Pas encore de clé pour ce profil : l'ajouter dans « Ma config ». » | carte en `credentials: profile`, aucune clé saisie pour ce profil | bouton « Ma config » du dashboard du profil |
| « Pas encore de lieu pour ce profil : le choisir dans « Ma config ». » | carte `weather` en `location: profile` sans `latitude`/`longitude` dans le YAML, et aucun lieu choisi | choisir le lieu dans « Ma config », ou donner un lieu par défaut dans le YAML |
| En prod seulement, une variable d'environnement semble ignorée (valeur de `.env` utilisée) | son nom commence par `HTTP_` : sous PHP-FPM, Symfony ne reprend pas ces variables de l'environnement (ce sont des en-têtes de requête). Cas de l'ancienne `HTTP_CA_FILE` (« unable to get local issuer certificate ») | ne jamais nommer une variable `HTTP_*` (vérifié par `tests/Config/EnvVariablesTest.php`) |
| Toutes les clés de « Ma config » redeviennent « Pas encore de clé » (fichiers `<profil>.credentials.json`) | `APP_SECRET` a changé (clés chiffrées avec), ou le dossier `PROFILE_DATA_DIR` a été perdu | ressaisir les clés ; en prod, garder `APP_SECRET` au vault et restaurer le volume depuis la sauvegarde |
| Après une modification du YAML, une carte n'est plus à la place choisie avec « Réorganiser » | une option de la carte a changé, donc son identifiant (`<profil>-<type>-<empreinte>`) : l'ordre enregistré ne la reconnaît plus, elle va à la fin de sa section | la replacer avec « Réorganiser », ou lui donner un `id:` fixe dans le YAML |
| « Ordre attendu… » ou « Cartes inconnues dans ce profil » en enregistrant l'ordre | le dashboard affiché est plus ancien que le YAML (cartes retirées entre-temps) | recharger la page puis réorganiser |
| « Ma config » : « Requête venue d'un autre site refusée » | le proxy devant le dashboard réécrit l'en-tête `Host` (ex. proxy Vite avec `changeOrigin: true`), ou page ouverte sous une autre adresse | garder le `Host` d'origine dans le proxy (Caddy le garde par défaut) ; ouvrir le dashboard par son adresse habituelle |
| « Utilisateur Audiobookshelf inconnu « x » (utilisateurs : …) » | faute dans l'option `user` | reprendre un nom de la liste du message |
| « … injoignable » (autre source) | `SourceUnavailableException` d'un client | la source tourne-t-elle ? L'URL est-elle bonne ? |
| « Erreur interne : … » | exception inattendue dans un type de carte | bug : écrire un test qui la reproduit, puis corriger. Trace complète dans les logs |
| « Pas de réponse du serveur (…) » | le navigateur n'atteint pas l'API | nginx, PHP-FPM, Caddy |
| « Type de carte sans composant React » | type absent de `assets/cards/registry.ts` | l'ajouter (la CI aurait dû le signaler) |

Rejouer l'appel de la carte : `curl -s http://localhost:8080/api/cards/<id> | jq`
en dev, `https://dashboard.example/api/cards/<id>` en prod. Les id sont dans
`/api/dashboards/<profil>`.

Les erreurs ne sont pas mises en cache : une source réparée réapparaît au
rafraîchissement suivant de la carte.

## La page affiche « Configuration du dashboard invalide »

La liste sous le message est complète. Corriger le YAML puis vérifier :
`bin/console dashboard:lint <fichier>`. Pas besoin de redémarrer : le fichier
est relu dès que sa date change.

## Une modification du YAML n'apparaît pas

- **En dev (Docker Desktop)** : un fichier remplacé par renommage (`sed -i`,
  Neovim avec son réglage par défaut) reste vu dans son ancienne version par le
  container, tant que son dossier n'est pas relu (cache du partage de fichiers
  de Docker Desktop). Forcer la relecture :
  `docker compose exec dashboard ls /app >/dev/null`. Pour l'éviter, enregistrer
  sur place : `:set backupcopy=yes` dans Neovim.

- Le fichier a-t-il bien changé de date dans le container ? Un fichier monté
  seul (pas son dossier) garde l'ancien contenu après un remplacement par
  renommage (Ansible, éditeurs) : monter le **dossier**.
- Les données vivantes d'une carte restent en cache `refresh` secondes, sauf si
  ses options changent.

## Page blanche en dev, « type MIME interdit (application/json) »

Le serveur Vite relaie tout chemin commençant par `/api` vers Symfony. Un
fichier du front servi sous `/api/…` (un dossier `assets/api/`) part donc vers
l'API, qui répond en JSON. Ne pas nommer de dossier `assets/api` ;
`assets/viteProxy.test.ts` le vérifie.

## Un container qui tourne apparaît « Arrêté »

Beszel ne le connaît pas sous ce nom : comparer `container:` avec le nom listé
dans l'interface de Beszel (onglet Containers du système). L'agent peut aussi
l'exclure (`EXCLUDE_CONTAINERS`).

## nginx répond 502 après une mise à jour

nginx doit résoudre le nom du container PHP à chaque requête (`resolver
127.0.0.11` et `fastcgi_pass` sur une variable, voir `docker/nginx/dashboard.conf`).
Sinon il garde l'adresse de l'ancien container.

## Mémoire et CPU des containers à 0 sur un Raspberry Pi

Raspberry Pi OS n'active pas le contrôleur mémoire des cgroups : Docker (donc
Beszel) ne mesure ni la mémoire ni le CPU par container. La carte `containers`
masque ces 0. Pour les mesurer, ajouter `cgroup_memory=1 cgroup_enable=memory`
à `/boot/firmware/cmdline.txt` puis redémarrer le Pi.

## Logs

- Dev : `docker compose logs -f dashboard nginx`, et `var/log/dev.log`.
- Prod : `docker logs dashboard` sur le serveur (le logger écrit sur stderr).

## Le service répond mais la carte dit « Ne répond pas »

La sonde part du serveur, pas du navigateur. Si l'hôte ne résout pas les noms
locaux, par exemple parce qu'un DNS IPv6 annoncé par la box passe
devant celui du LAN : renseigner `check:` avec l'adresse IP et le port du
service, ou donner au container le DNS du LAN (`dns: 10.0.0.35`).

## CI rouge

- *registre des cartes* (vitest) : un type existe côté PHP et pas côté React,
  ou l'inverse. Comparer `bin/console dashboard:card-types` et `cardRegistry`.
- *CardAutoconfigurationTest* : une classe de `src/Card/Type` sans `#[AsCard]`.
- *failOnDeprecation* : phpunit échoue sur toute dépréciation ; la corriger
  plutôt que de baisser le niveau.

## Docker Hub : « 429 Too Many Requests »

Limite des téléchargements anonymes. Attendre, ou `docker login`.
