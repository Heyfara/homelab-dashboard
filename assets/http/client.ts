import type { CardResult, CredentialView, DashboardView, PlaceView, ProfileConfigView, ProfileSummary, WeatherLocationView } from './types'

/** Erreur renvoyée par l'API ({ error, details? }) ou réponse inattendue. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly details: string[] = [],
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

async function getJson<T>(url: string, signal?: AbortSignal, init: RequestInit = {}): Promise<T> {
  const response = await fetch(url, { headers: { Accept: 'application/json' }, signal, ...init })
  const body: unknown = response.status === 204 ? undefined : await response.json().catch(() => null)

  if (!response.ok) {
    const error = body as { error?: string; details?: string[] } | null
    throw new ApiError(response.status, error?.error ?? `Le serveur a répondu ${response.status}`, error?.details ?? [])
  }

  return body as T
}

export const fetchProfiles = (signal?: AbortSignal) => getJson<ProfileSummary[]>('/api/profiles', signal)

export const fetchDashboard = (profile: string, signal?: AbortSignal) =>
  getJson<DashboardView>(`/api/dashboards/${encodeURIComponent(profile)}`, signal)

/** Données d'une carte ; `feed` pour un de ses flux (rythme propre, voir CardView.feeds). */
export const fetchCard = (id: string, signal?: AbortSignal, feed?: string) =>
  getJson<CardResult>(`/api/cards/${encodeURIComponent(id)}${feed ? `?feed=${encodeURIComponent(feed)}` : ''}`, signal)

const configUrl = (profile: string) => `/api/profiles/${encodeURIComponent(profile)}/config`
const credentialsUrl = (profile: string) => `/api/profiles/${encodeURIComponent(profile)}/credentials`
const sendJson = (method: string, body: unknown): RequestInit => ({
  method,
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  body: JSON.stringify(body),
})

/** « Ma config » du profil : une section par carte configurable, jamais les clés elles-mêmes. */
export const fetchConfig = (profile: string, signal?: AbortSignal) => getJson<ProfileConfigView>(configUrl(profile), signal)

/** Lieu de la météo choisi par le profil ; ApiError 422 avec la raison s'il est refusé. */
export const saveWeatherLocation = (profile: string, place: { place: string; latitude: number; longitude: number }) =>
  getJson<WeatherLocationView>(`${configUrl(profile)}/weather`, undefined, sendJson('PUT', place))

/** Oublie le lieu choisi : retour à celui du YAML. */
export const resetWeatherLocation = (profile: string) => getJson<void>(`${configUrl(profile)}/weather`, undefined, { method: 'DELETE' })

/** Villes dont le nom ressemble à `query` (géocodage Open-Meteo, par le serveur). */
export const searchPlaces = (query: string, signal?: AbortSignal) => getJson<PlaceView[]>(`/api/places?q=${encodeURIComponent(query)}`, signal)

/** Vérifie la clé auprès du service puis l'enregistre ; ApiError 422 avec la raison si elle est refusée. */
export const saveCredential = (profile: string, kind: string, key: string) =>
  getJson<Pick<CredentialView, 'configured' | 'account' | 'updatedAt'>>(`${credentialsUrl(profile)}/${encodeURIComponent(kind)}`, undefined, sendJson('PUT', { key }))

export const deleteCredential = (profile: string, kind: string) =>
  getJson<void>(`${credentialsUrl(profile)}/${encodeURIComponent(kind)}`, undefined, { method: 'DELETE' })

const layoutUrl = (profile: string) => `/api/profiles/${encodeURIComponent(profile)}/layout`

/** Ordre des cartes choisi par le profil (identifiants, toutes sections confondues). */
export const saveLayout = (profile: string, order: string[]) =>
  getJson<void>(layoutUrl(profile), undefined, sendJson('PUT', { order }))

/** Oublie l'ordre choisi : retour à celui du YAML. */
export const resetLayout = (profile: string) => getJson<void>(layoutUrl(profile), undefined, { method: 'DELETE' })
