// Types des réponses de l'API Symfony (src/Controller). À garder alignés.

export const PROFILE_COLORS = ['slate', 'indigo', 'sky', 'emerald', 'amber', 'rose', 'violet', 'teal'] as const
export type ProfileColor = (typeof PROFILE_COLORS)[number]

export type ProfileSummary = {
  slug: string
  name: string
  color: ProfileColor
  /** Le profil suit des sujets ntfy (cloche) ; seulement dans DashboardView */
  notifications?: boolean
}

/** Une carte telle que la décrit GET /api/dashboards/{profile}. P = props fixes du type. */
export type CardView<P = Record<string, unknown>> = {
  id: string
  type: string
  title: string | null
  /** Largeur, en colonnes */
  span: number
  /** Hauteur, en rangées (une rangée = une carte service) */
  rows: number
  refresh: number
  /** Flux supplémentaires et leur rythme en secondes (ex. lectures en cours Jellyfin : { sessions: 10 }) */
  feeds?: Record<string, number>
  props: P
}

export type SectionView = {
  title: string
  columns: number
  /** Identifiant de l'onglet (TabView.slug). Absent d'une mise en page gardée avant les onglets. */
  tab?: string
  cards: CardView[]
}

/** Un onglet du profil : `slug` dans l'adresse (/<profil>/<slug>), `title` affiché. */
export type TabView = { slug: string; title: string }

export type DashboardView = {
  profile: ProfileSummary
  /** L'ordre des cartes a été changé par la personne (mode « Réorganiser ») */
  customized: boolean
  settings: { columns: number; refresh: number }
  /** Dans l'ordre ; absent d'une mise en page gardée avant les onglets */
  tabs?: TabView[]
  sections: SectionView[]
}

/** GET /api/cards/{id}. D = données vivantes du type. */
export type CardResult<D = Record<string, unknown>> =
  /** stale : valeur plus vieille que le `refresh` de la carte, que le serveur relit en ce moment */
  | { status: 'ok'; data: D; error: null; fetchedAt: string; stale?: boolean }
  | { status: 'error'; data: null; error: string; fetchedAt: string }

/** GET /api/profiles/{slug}/config : une section par carte configurable du profil. */
export type ProfileConfigView = {
  /** Clés d'API des cartes en credentials: profile */
  credentials: CredentialView[]
  /** Lieu de la carte météo en location: profile, null s'il n'y en a pas */
  weather: WeatherLocationView | null
}

/** Lieu de la météo (WeatherLocation::view) : celui choisi, sinon celui du YAML, sinon vide. */
export type WeatherLocationView = {
  place: string | null
  latitude: number | null
  longitude: number | null
  /** Choisi par la personne (sinon : lieu par défaut du YAML) */
  custom: boolean
  updatedAt: string | null
}

/** Un lieu trouvé par GET /api/places?q= */
export type PlaceView = {
  name: string
  /** Département, sinon région */
  region: string | null
  country: string | null
  latitude: number
  longitude: number
}

/** Une clé d'API de « Ma config ». La clé n'est jamais renvoyée. */
export type CredentialView = {
  id: string
  label: string
  /** Où créer la clé, quels droits cocher */
  help: string
  configured: boolean
  /** Compte trouvé à la vérification : Zoe.1234 */
  account: string | null
  updatedAt: string | null
}

/** Un message ntfy, tel que le renvoie GET /api/profiles/{slug}/notifications. */
export type NotificationView = {
  id: string
  /** ISO 8601, heure de Paris */
  time: string
  topic: string
  title: string | null
  message: string
  /** 1 (min) à 5 (urgent) */
  priority: number
  /** Émojis ntfy (warning, battery…) et mots-clés */
  tags: string[]
  /** Adresse à ouvrir depuis le message */
  click: string | null
  read: boolean
}

/** GET /api/profiles/{slug}/notifications : messages des sujets suivis, le plus récent d'abord. */
export type NotificationsView = {
  topics: { id: string; name: string }[]
  /** Interface web de ntfy */
  link: string | null
  unread: number
  messages: NotificationView[]
}
