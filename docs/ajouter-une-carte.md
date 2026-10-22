# Ajouter un type de carte

Exemple suivi : une carte `uptime` qui affiche depuis combien de temps un
container tourne. Le projet est en **TDD** : chaque étape commence par un test
qui échoue.

## 0. Décider

- **Identifiant** du type (`uptime`) : écrit dans le YAML et dans le registre React.
- **Options** du YAML (`service: immich`) et leurs valeurs par défaut.
- **Props fixes** (`describe`) : ce qui s'affiche sans appel réseau.
- **Données vivantes** (`fetch`) : ce qu'il faut lire à la source.
- **Source** : un client existant dans `src/Source/` ou un nouveau (étape 1).

## 1. Si besoin, un client de source (`src/Source/<Source>/`)

Test d'abord, dans `tests/Source/<Source>/` avec `MockHttpClient` (voir
`BeszelClientTest`) : réponse normale, réponse vide ou absente, source
injoignable.

Règles :
- le client renvoie des objets à lui (`ContainerState`), pas le JSON brut ;
- une source injoignable lève `App\Source\SourceUnavailableException` avec un
  message lisible (« Beszel injoignable : … ») : il s'affichera sur la carte ;
- timeout court (`'timeout' => 3`) : la carte attend, pas toute la page.

Si la source demande un secret, le lire par `#[Autowire('%env(NOM)%')]`, le
déclarer vide dans `.env`, et ajouter la variable au rôle Ansible.

## 2. Le type de carte PHP (`src/Card/Type/UptimeCard.php`)

Test d'abord : `tests/Card/Type/UptimeCardTest.php`, sur le modèle de
`ServiceCardTest` (un `Dashboard` construit à la main, les clients branchés sur
un `MockHttpClient`). Couvrir : options valides et refusées, `describe`,
`fetch` nominal, `fetch` quand la source tombe.

```php
#[AsCard('uptime')]
final readonly class UptimeCard implements CardTypeInterface
{
    public function __construct(private DockerClient $docker) {}

    public function configureOptions(OptionsResolver $resolver, Dashboard $dashboard): void
    {
        $resolver->setRequired('service')
            ->setAllowedValues('service', array_map('strval', array_keys($dashboard->services)));
    }

    public function describe(CardInstance $card, Dashboard $dashboard): array
    {
        return ['name' => $dashboard->services[$card->options['service']]->name];
    }

    public function fetch(CardInstance $card, Dashboard $dashboard): array
    {
        // lire la source ; laisser remonter SourceUnavailableException
    }
}
```

`#[AsCard]` suffit à enregistrer le type : rien à déclarer ailleurs.
`CardAutoconfigurationTest` vérifie que chaque classe de `src/Card/Type` porte
l'attribut. Cache et erreurs sont gérés par `CardDataFetcher` : ne pas les
refaire dans la carte.

Si la carte apparaît dans `tests/fixtures/dashboard.yaml`, ajouter ses réponses
HTTP dans `tests/Http/FakeHomelab.php` et un cas dans `tests/Controller/ApiTest.php`.

### Données à rafraîchir plus souvent : un flux

Si une partie des données doit suivre un autre rythme (lectures en cours,
débits…), implémenter aussi `CardFeedsInterface` :

```php
public function feeds(CardInstance $card): array
{
    return ['sessions' => $card->options['now_playing_refresh']];   // nom → secondes
}

public function fetchFeed(string $feed, CardInstance $card, Dashboard $dashboard): array
{
    return ['sessions' => …];
}
```

Le composant reçoit `feeds.sessions` (un `CardResult`, `null` avant la 1re
réponse). Exemple complet : `JellyfinLatestCard`.

### Images qui exigent une clé : le relais

Si les images de la source demandent une authentification (Immich, Calibre-Web),
ne jamais mettre la clé dans l'URL envoyée au navigateur. Le type implémente
`ImageSourceInterface` et ne renvoie que des adresses signées :

```php
public function __construct(private ImmichClient $immich, private ImageUrlSigner $signer) {}

// dans fetch() : chemin libre, signé avec l'id de la carte
'thumb' => $this->signer->url($card->id, $asset->id.'/thumbnail'),

public function fetchImage(string $path, CardInstance $card, Dashboard $dashboard): CardImage
{
    [$id, $size] = explode('/', $path, 2);   // valider : le chemin vient d'une URL

    return $this->immich->thumbnail($api, $id, $size);
}
```

`CardImageFetcher` vérifie la signature, n'accepte que `image/*` de 5 Mo au
plus et garde l'image 24 h. Exemple complet : `ImmichCard`, testé de bout en
bout dans `ApiTest::testImmichCardWithRelayedThumbnails`.

### Une clé par personne : `Credentials`

Si la source montre les données d'un compte (clé d'API personnelle), ne pas
injecter la clé dans le client avec `#[Autowire('%env(…)%')]`, mais le service
`App\Source\Credentials`. La carte expose l'option `credentials` et la passe au
client :

```php
// Client
public function __construct(private HttpClientInterface $httpClient, private Credentials $credentials) {}

public function account(string $api, ?string $credentials = null): array
{
    $key = $this->credentials->get('GW2_API_KEY', $credentials);   // GW2_API_KEY ou GW2_API_KEY_<NOM>
    // …
}

// Type de carte
$resolver->setDefault('credentials', null)
    ->setAllowedTypes('credentials', ['null', 'string'])
    ->setAllowedValues('credentials', static fn (?string $c): bool => null === $c || Credentials::isValidVariant($c));
```

Pour accepter aussi `credentials: profile` (clé saisie dans « Mes clés ») :

1. passer la clé au client plutôt qu'un nom, et la choisir dans la carte avec
   `CardCredentials::resolve($card, 'MA_SOURCE_API_KEY', 'ma-source')` ;
2. déclarer le service dans « Mes clés » : une classe qui implémente
   `App\Profile\CredentialKindInterface` (identifiant, libellé, aide, et
   `verify()` qui interroge le service et renvoie le compte trouvé, ou lève
   `InvalidCredentialException` avec un message pour l'écran).

Exemple complet : `Gw2Client`, `Gw2Card` et `Profile/Kind/Gw2CredentialKind`.
Ajouter la carte à la liste de « Identifiants par personne » dans
`docs/configuration.md`.

## 3. Le composant React (`assets/cards/UptimeCard.tsx`)

Test d'abord : `assets/cards/UptimeCard.test.tsx`, sur le modèle de
`ServiceCard.test.tsx` : rendu avec données, rendu avec `result = null`
(chargement), cas limites. La logique de présentation (calculs, libellés) va
dans une fonction pure testée à part, comme `serviceStatus.ts`.

```tsx
export type UptimeProps = { name: string }                // = describe() PHP
export type UptimeData = { startedAt: string | null }     // = fetch() PHP

export const UptimeCard: CardComponent<UptimeProps, UptimeData> = ({ card, result }) => { … }
```

Puis l'enregistrer dans `assets/cards/registry.ts` :

```ts
export const cardRegistry: Record<string, CardComponent> = {
  service: ServiceCard,
  uptime: UptimeCard,
}
```

Le cadre, les erreurs et le rafraîchissement sont gérés par `CardSlot` : le
composant ne dessine que le contenu. Sa hauteur est imposée par la grille
(`rows` × `--card-row`) : le contenu doit tenir dans `h-full` sans déborder
(voir `JellyfinLatestCard`, qui n'affiche que les affiches entières). Les classes Tailwind s'écrivent en entier
(pas de `bg-${couleur}-500`, que Tailwind ne détecte pas). Tout lien passe par
`ExternalLink` (`assets/ui/`) : nouvel onglet, `rel="noopener noreferrer"` ; un
test refuse les `<a>` bruts dans `assets/cards/`.

## 4. Documenter et vérifier

- Ajouter la section du type dans `docs/configuration.md` (options, rendu).
- Ajouter un exemple dans `examples/dashboard.yaml`.
- `make test && make lint` ; en local, `make up` pour voir la carte.
- La CI compare la liste des types PHP aux clés de `cardRegistry` : un oubli
  d'un côté la fait échouer.
