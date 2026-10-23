// Types des réponses de l'API Symfony (src/Controller). À garder alignés.

export const PROFILE_COLORS = ['slate', 'indigo', 'sky', 'emerald', 'amber', 'rose', 'violet', 'teal'] as const
export type ProfileColor = (typeof PROFILE_COLORS)[number]

export type ProfileSummary = {
  slug: string
  name: string
  color: ProfileColor
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
  cards: CardView[]
}

export type DashboardView = {
  profile: ProfileSummary
  /** L'ordre des cartes a été changé par la personne (mode « Réorganiser ») */
  customized: boolean
  settings: { columns: number; refresh: number }
  sections: SectionView[]
}

/** GET /api/cards/{id}. D = données vivantes du type. */
export type CardResult<D = Record<string, unknown>> =
  | { status: 'ok'; data: D; error: null; fetchedAt: string }
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
