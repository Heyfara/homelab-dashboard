/** Types d'appareil (NetworkCard::TYPES côté PHP). */
export type DeviceType = 'box' | 'network' | 'server' | 'computer' | 'laptop' | 'phone' | 'tablet' | 'tv' | 'printer' | 'speaker' | 'camera' | 'iot' | 'console' | 'other'

/** Position déduite des points d'accès (Placement côté PHP). */
export type Place = {
  zone: string
  /** Point d'accès, null sur le switch de la baie */
  ap: string | null
  link: 'wifi' | 'cable' | null
  /** Port du point d'accès : lan2, ra0… */
  port: string | null
  /** Bande Wi-Fi en GHz : '2.4', '5' ou '6' */
  band: string | null
  /** Signal en dBm */
  signal: number | null
  /** Plus vu à cet endroit depuis : la position est gardée quelques minutes */
  seenAt: string | null
}

/** Un appareil de NetworkCard::fetch(). */
export type Device = {
  mac: string
  name: string | null
  vendor: string | null
  type: DeviceType
  ip: string | null
  /** Adresse du bail statique de la Freebox, s'il y en a un */
  expectedIp: string | null
  online: boolean
  lastSeen: string | null
  /** Adresse MAC privée (aléatoire), fréquente sur les téléphones */
  randomMac: boolean
  /** null : position incertaine, ou appareil hors ligne jamais placé */
  place: Place | null
  /** Première apparition sur le réseau (Freebox) */
  firstSeen: string | null
  /** Changements de point d'accès et retours après coupure, sur la dernière heure */
  changes: number
}

export type DeviceFilter = 'all' | 'online' | 'unknown'

export const TYPE_LABELS: Record<DeviceType, string> = {
  box: 'Box',
  network: 'Réseau',
  server: 'Serveur',
  computer: 'Ordinateur',
  laptop: 'Portable',
  phone: 'Téléphone',
  tablet: 'Tablette',
  tv: 'TV et multimédia',
  printer: 'Imprimante',
  speaker: 'Enceinte',
  camera: 'Caméra',
  iot: 'Objet connecté',
  console: 'Console',
  other: 'Autre',
}

export function deviceLabel(d: Device): string {
  if (d.name !== null) return d.name
  if (d.vendor !== null) return `Appareil ${d.vendor}`
  return d.randomMac ? 'Adresse privée' : 'Appareil sans nom'
}

/** Personne ne lui a donné de nom : ni la Freebox, ni la configuration. */
export const isUnknown = (d: Device) => d.name === null

/** L'appareil n'a pas l'adresse que lui réserve la Freebox. */
export const ipMismatch = (d: Device) => d.expectedIp !== null && d.ip !== null && d.ip !== d.expectedIp

/** Zone des appareils en ligne qu'aucun point d'accès ne situe. */
export const UNCERTAIN = 'Position incertaine'

export const bandLabel = (band: string) => `${band.replace('.', ',')} GHz`

export function placeLabel(p: Place): string {
  if (p.link === 'wifi') return `${p.zone} · Wi-Fi${p.band !== null ? ` ${bandLabel(p.band)}` : ''}`
  return `${p.zone} · câble${p.ap !== null && p.port !== null ? ` ${p.port}` : ''}`
}

/** Apparu depuis moins de 24 h : à reconnaître. */
export const isNew = (d: Device, now: Date = new Date()) => d.firstSeen !== null && now.getTime() - new Date(d.firstSeen).getTime() < 24 * 3600_000

/** Seuil de changements de point d'accès ou de coupures en une heure. */
export const UNSTABLE_CHANGES = 4

export const isUnstable = (d: Device) => d.changes >= UNSTABLE_CHANGES

/** Au-dessous, la connexion devient instable. */
export const weakSignal = (p: Place) => p.signal !== null && p.signal < -75

export const zoneOf = (d: Device): string | null => d.place?.zone ?? (d.online ? UNCERTAIN : null)

/** Nombre d'appareils par zone, dans l'ordre donné ; les zones vides sont omises. */
export function countZones(devices: Device[], zones: string[]): { zone: string; count: number }[] {
  return [...zones, UNCERTAIN]
    .map((zone) => ({ zone, count: devices.filter((d) => zoneOf(d) === zone).length }))
    .filter((z) => z.count > 0)
}

const FILTERS: Record<DeviceFilter, (d: Device) => boolean> = {
  all: () => true,
  online: (d) => d.online,
  unknown: isUnknown,
}

export const filterDevices = (devices: Device[], filter: DeviceFilter, zone: string | null = null) =>
  devices.filter((d) => FILTERS[filter](d) && (zone === null || zoneOf(d) === zone))

export const countDevices = (devices: Device[]): Record<DeviceFilter, number> => ({
  all: devices.length,
  online: devices.filter(FILTERS.online).length,
  unknown: devices.filter(FILTERS.unknown).length,
})
