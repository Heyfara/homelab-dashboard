/** Types d'appareil (NetworkCard::TYPES côté PHP). */
export type DeviceType = 'box' | 'network' | 'server' | 'computer' | 'laptop' | 'phone' | 'tablet' | 'tv' | 'printer' | 'speaker' | 'camera' | 'iot' | 'console' | 'other'

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

const FILTERS: Record<DeviceFilter, (d: Device) => boolean> = {
  all: () => true,
  online: (d) => d.online,
  unknown: isUnknown,
}

export const filterDevices = (devices: Device[], filter: DeviceFilter) => devices.filter(FILTERS[filter])

export const countDevices = (devices: Device[]): Record<DeviceFilter, number> => ({
  all: devices.length,
  online: devices.filter(FILTERS.online).length,
  unknown: devices.filter(FILTERS.unknown).length,
})
