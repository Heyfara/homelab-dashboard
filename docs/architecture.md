# Architecture

Schéma complet (classes, flux, déploiement) : <https://claude.ai/artifact/4nJ7JAaS7KRcZEvSKu8KQz>.

## Chemin d'une requête

```
navigateur ─▶ Caddy (TLS, dashboard.example) ─▶ nginx partagé ─┬─ /api/*  ─▶ PHP-FPM (Symfony)
                                                            └─ le reste ─▶ public/build (front)
```

1. Le navigateur charge `public/build/index.html` (produit par Vite) : l'appli React.
2. `GET /api/dashboards/{profil}` renvoie sections et cartes, avec les **props
   fixes** de chaque carte (nom, lien, icône) : la page s'affiche tout de suite.
3. Chaque carte appelle `GET /api/cards/{id}` toutes les `refresh` secondes
   pour ses **données vivantes**. Une source en panne ne grise que sa carte.

## Côté serveur (`src/`)

| Dossier | Rôle |
|---|---|
| `Dashboard/` | lire `dashboard.yaml` (`YamlDashboardLoader` + `DashboardConfiguration`), vérifier les renvois (`DashboardValidator`), servir le résultat en cache (`DashboardProvider`) |
| `Dashboard/Model/` | objets immuables : `Dashboard`, `Profile`, `Section`, `CardInstance`, `ServiceDefinition`, `Host`, `Settings` |
| `Card/` | contrat d'un type de carte (`CardTypeInterface`, `#[AsCard]`), registre, `CardDataFetcher` (cache + capture d'erreurs), `CardResult` |
| `Card/Type/` | un fichier par type de carte |
| `Source/` | clients des sources : `Beszel/` (API du hub), `Container/` (état des containers), `Http/` (sonde), API des services ; `Credentials` (clés par variable d'environnement) |
| `Profile/` | « Mes clés » : coffre chiffré par profil (`ProfileCredentials`), ordre des cartes (`ProfileLayout`), fichiers privés par profil (`ProfileFiles`), services dont on peut saisir sa clé (`CredentialKindInterface`), choix de la clé d'une carte (`CardCredentials`) |
| `Controller/` | routes de lecture, « Mes clés » et ordre des cartes (seules écritures), sans logique |
| `Command/` | `dashboard:lint`, `dashboard:card-types` |
| `EventListener/` | toute erreur sous `/api` répond en JSON |

Points clés :

- **Config relue sans redémarrage** : `DashboardProvider` met le dashboard en
  cache sous une clé qui contient la date de modification du fichier.
- **Cache des données** : `CardDataFetcher` garde chaque réponse `refresh`
  secondes (clé = id de la carte + options).
- **Flux** : un type de carte peut implémenter `CardFeedsInterface` pour avoir,
  en plus de ses données principales, des flux à leur propre rythme (lectures
  en cours Jellyfin toutes les 10 s, derniers ajouts toutes les 5 min). Chaque
  flux a son cache et son appel (`GET /api/cards/{id}?feed=…`) ; la mise en page
  les annonce (`feeds: { sessions: 10 }`) et `CardSlot` les interroge à part,
  puis passe leurs résultats au composant (`feeds`). On ne rafraîchit ainsi
  souvent que ce qui le mérite.
- **Cache du dashboard** : sa clé contient la date du YAML **et** l'empreinte
  des sources des cartes (`DashboardProvider::codeVersion`) : les options
  complétées par le code (valeurs par défaut) ne survivent pas à un code modifié. Plusieurs onglets ouverts ne
  multiplient pas les appels aux sources. Une erreur n'est jamais mise en cache.
- **Erreurs** : une `SourceUnavailableException` (source injoignable) devient un
  `CardResult` en erreur, avec son message affiché sur la carte. Une config
  invalide renvoie un 503 avec la liste complète des erreurs.
- **Cache APCu en prod**, système de fichiers en dev, mémoire en test.

## Données propres à chaque profil : « Mes clés » et ordre des cartes

Tout le reste est sans état (YAML, environnement, caches). Exceptions, saisies
par chaque personne depuis son dashboard :

- les clés d'API de « Mes clés » (`/<profil>/cles`), pour ses propres cartes
  (`credentials: profile`) ;
- l'ordre de ses cartes (bouton « Réorganiser »).

`ProfileFiles` les range dans `PROFILE_DATA_DIR` (vide = `var/profiles`) :
`<profil>.credentials.json` et `<profil>.layout.json`, hors de `public/`,
droits `0600` et dossier `0700`, écriture atomique sous verrou.

### Ordre des cartes

- `ProfileLayout` garde la liste des identifiants dans l'ordre choisi ;
  `DashboardController` l'applique section par section et renvoie
  `customized`. Le YAML reste la référence : une carte ne change jamais de
  section, une carte ajoutée depuis va à la fin de sa section, une carte
  retirée est oubliée.
- Il repose sur des **identifiants stables** : sans `id:` dans le YAML, une
  carte s'appelle `<profil>-<type>-<empreinte des options>` (ni la position, ni
  `title`/`span`/`rows`/`refresh` n'y entrent). Changer une option change
  l'identifiant : la carte repart à la fin de sa section.
- Front : mode « Réorganiser » de `DashboardPage`. Flèches ← → sur chaque
  carte (clavier, tactile) et glisser-déposer natif HTML5 (ordinateur) : la
  carte tenue se place avant ou après la carte survolée, la grille se recompose
  en direct ; lâchée ailleurs ou Échap, elle revient. Rien n'est enregistré
  avant « Enregistrer » (`PUT /api/profiles/{profil}/layout`) ; « Ordre par
  défaut » l'oublie (`DELETE`). Pas de bibliothèque de glisser-déposer.

### « Mes clés »

- **Chiffrement** : chaque clé est chiffrée (XChaCha20-Poly1305, `ext-sodium`)
  avec une clé dérivée d'`APP_SECRET`, et liée à son profil et à son service.
  Changer `APP_SECRET` rend les clés illisibles : elles sont à ressaisir.
- **Jamais renvoyée** : l'API ne dit que « configurée » et le compte vérifié.
  Une clé est vérifiée auprès du service avant d'être enregistrée.
- **Pas de mot de passe** (choix de la famille) : sur le réseau de la maison,
  n'importe qui peut remplacer ou supprimer une clé (ou réordonner les cartes
  d'un autre), mais pas la lire. Les écritures (trait `SameSiteWrites`) refusent les requêtes venues d'un autre site (`Sec-Fetch-Site`,
  `Origin`) et exigent du JSON.
- **Cache** : l'empreinte du fichier des clés du profil entre dans la clé de cache de
  ses cartes ; une clé ajoutée sert tout de suite.
- **Sauvegarde** : ce dossier n'est pas dans Ansible. En prod, c'est un volume
  sauvegardé chaque nuit.

## État des containers

Il vient de **Beszel**, lu avec un compte en lecture seule, et pas d'un accès à
l'API Docker. Un docker-socket-proxy laisse lire la fiche complète d'un
container (`/containers/<nom>/json`), **variables d'environnement comprises**,
donc des mots de passe ; pour une machine distante il faudrait en plus ouvrir
un port. Les agents Beszel, eux, ne remontent que la liste des containers qui
tournent (nom, texte d'état, santé, CPU, mémoire), sans leur configuration.

- `ContainerStatusSource` est l'interface que voient les cartes ;
  `BeszelContainerSource` l'implémente. Changer de source ne touche pas les cartes.
- Un container **absent** de la liste, ou dont la fiche a plus de 2 minutes de
  retard sur la plus récente du même système, est **arrêté** (le hub garde
  ~10 min la fiche d'un container arrêté). On ne distingue pas arrêté, supprimé
  ou en échec.
- Un système Beszel qui n'est pas `up` donne une erreur « Beszel ne reçoit plus
  les données de … » au lieu de déclarer tous ses containers arrêtés.
- Délai : les agents écrivent chaque minute, le client garde les listes 15 s.

Beszel n'accepte ni plugin ni métrique personnalisée. Il offre aussi, sans
code de collecte en plus : la fiche d'un container sans `Env`
(`/api/beszel/containers/info`, containers qui tournent seulement), les
services systemd (`SERVICE_PATTERNS`, agent Beszel natif) et des sondes
réseau HTTP/TCP/ping/DNS avec expiration du certificat (réglées dans l'UI de
Beszel). Si ses limites gênent un jour, la piste notée est un petit collecteur
maison par machine qui *pousse* une photo filtrée (`docker ps -a`) vers le
dashboard : une nouvelle implémentation de `ContainerStatusSource`.

## Relais d'images

Certaines images exigent une authentification (miniatures Immich, couvertures
Calibre-Web). Le navigateur ne doit jamais recevoir la clé : le dashboard les
relaie (`GET /api/cards/{id}/image?path=…&sig=…`, `CardImageController`).

- Le type de carte implémente `ImageSourceInterface` et produit ses adresses
  d'image avec `ImageUrlSigner` : HMAC du couple carte + chemin avec
  `APP_SECRET`. Une adresse non signée est refusée (403) : pas de relais ouvert.
- `CardImageFetcher` n'accepte que des réponses `image/*` de 5 Mo au plus, et
  les garde 24 h dans le pool `cache.images` (sur disque, même en prod).

## Côté navigateur (`assets/`)

| Dossier | Rôle |
|---|---|
| `http/` | `client.ts` (appels, `ApiError`) et `types.ts` (forme des réponses, à garder alignée avec les contrôleurs) |
| `hooks/` | `usePath` (routage minimal), `useJson` (requête unique), `useCardData` (rafraîchissement d'une carte) |
| `layout/` | `ProfilePicker`, `DashboardPage`, `SectionGrid`, `CardSlot`, `CardFrame` |
| `cards/` | `registry.ts` + un composant par type de carte |
| `ui/` | petits composants partagés : `StatusPill`, `ServiceIcon`, `Sparkline` (courbe), `TimeSeries` (graphique temporel avec réticule et infobulle, souris et clavier), `RingGauge` (jauge en anneau), `icons.tsx` (icônes au trait, sans bibliothèque), `format.ts` (octets, débits, dates relatives en français), `history.ts`, `MediaSlider` et `MediaTile` (vignettes qui défilent : flèches, glisser, doigt), `ExternalLink` |
| `public/` | fichiers copiés tels quels par Vite (favicon), servis sous `/build/` en prod |

Couleurs des données : `text-down` / `bg-down` (reçu), `text-up` / `bg-up`
(envoyé) et `bg-voie-{seed,upload,specialization,competitivity}` (voies C411),
définies dans `app.css` et validées pour les daltoniens (skill
dataviz, `validate_palette.js`, clair et sombre). Une couleur de donnée
accompagne toujours une icône ou un libellé ; les textes restent dans les
couleurs de texte. Les couleurs d'état (vert, ambre, rouge) sont réservées aux
états. Toute nouvelle palette de catégories passe par le validateur avant
d'être utilisée.

`CardSlot` choisit le composant par `card.type` dans `cardRegistry`, lui passe
`card` (props fixes) et `result` (données vivantes, `null` avant la 1re réponse).

## Choix et dépendances

Règle : un paquet n'entre que s'il fait un travail qu'on ne réécrirait pas en
quelques dizaines de lignes, et jamais de bundle tiers.

| Écarté | Remplacé par |
|---|---|
| Twig, vite-bundle | Vite génère lui-même `index.html` |
| Serializer, Validator | `JsonResponse`, arbre `Config` + `OptionsResolver` |
| Monolog | logger intégré de Symfony (stderr) |
| react-router | `usePath` (deux écrans) |
| TanStack Query | `useCardData` |
| FrankenPHP | PHP-FPM derrière le nginx partagé |
| Prometheus, cAdvisor | Beszel (déjà en place) |
| docker-socket-proxy | Beszel : voir « État des containers » |

Dépendances de test seulement : phpunit et le pack de test Symfony ; vitest,
jsdom et Testing Library.
