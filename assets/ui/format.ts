const UNITS = ['o', 'Ko', 'Mo', 'Go', 'To', 'Po']
const number = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 })
const relative = {
  long: new Intl.RelativeTimeFormat('fr', { numeric: 'auto' }),
  short: new Intl.RelativeTimeFormat('fr', { numeric: 'auto', style: 'short' }),
}

/** Taille en octets, unités décimales comme qBittorrent et les disques : 1,5 Go. */
export function formatBytes(bytes: number): string {
  let value = bytes
  let unit = 0
  while (value >= 1000 && unit < UNITS.length - 1) {
    value /= 1000
    unit++
  }
  return `${number.format(value)} ${UNITS[unit]}`
}

/** Débit en octets par seconde : 1,5 Mo/s. */
export const formatSpeed = (bytesPerSecond: number) => `${formatBytes(bytesPerSecond)}/s`

export const formatNumber = (value: number) => number.format(value)

/** « il y a 3 heures », « hier »… ; en `short` : « il y a 3 h ». */
export function formatRelative(iso: string, now: Date = new Date(), style: 'long' | 'short' = 'long'): string {
  const rtf = relative[style]
  const seconds = (new Date(iso).getTime() - now.getTime()) / 1000
  const abs = Math.abs(seconds)
  if (abs < 60) return 'à l’instant'
  if (abs < 3600) return rtf.format(Math.round(seconds / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(seconds / 3600), 'hour')
  if (abs < 30 * 86400) return rtf.format(Math.trunc(seconds / 86400), 'day')
  if (abs < 365 * 86400) return rtf.format(Math.round(seconds / (30 * 86400)), 'month')
  return rtf.format(Math.round(seconds / (365 * 86400)), 'year')
}

/** Durée arrondie à l'unité la plus parlante : « 45 s », « 12 min », « 3 h », « 44 j ». */
export function formatDuration(seconds: number): string {
  if (seconds < 60) return `${Math.floor(seconds)} s`
  if (seconds < 3600) return `${Math.floor(seconds / 60)} min`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)} h`
  return `${Math.floor(seconds / 86400)} j`
}

const dateTime = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })

/** « 19 oct., 21:30 » */
export const formatDateTime = (iso: string) => dateTime.format(new Date(iso))

/** Position de lecture : « 19:19 », « 1:17:14 ». */
export function formatClock(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = String(s % 60).padStart(2, '0')
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`
}
