import type { CardComponent } from './registry'
import { diskTone, formatGiB, formatUsage } from './MachineCard'
import { formatBytes, formatNumber } from '../ui/format'
import { DatabaseIcon } from '../ui/icons'
import { RingGauge } from '../ui/RingGauge'
import { StatusPill, toneDot, type Tone } from '../ui/StatusPill'

/** Props de StorageCard::describe() côté PHP. */
export type StorageProps = { title: string; system: string }

/** Données de StorageCard::fetch() côté PHP (Go binaires, comme Beszel). */
export type StorageData = {
  percent: number | null
  used: number | null
  total: number | null
  free: number | null
  /** État du pool btrfs/ZFS (option pool), null si non demandé */
  health: 'ONLINE' | 'DEGRADED' | 'UNKNOWN' | string | null
  /** Droite des moindres carrés sur la période ; null tant que le recul manque. */
  growth: { perDay: number; fullInDays: number | null; days: number } | null
  /** Disques de l'option disks, dans l'ordre (SMART de Beszel) */
  disks: StorageDisk[]
  /** Dernier scrub (check Healthchecks), null si non configuré */
  scrub: StorageScrub | null
}

export type StorageDisk = {
  serial: string
  model: string | null
  /** Octets */
  capacity: number | null
  /** Verdict SMART (PASSED, FAILED…) ; MISSING = absent de Beszel */
  state: string
  temperature: number | null
  hours: number | null
  /** Attributs SMART bruts présents : reallocated, pending, uncorrectable, crc */
  counters: Partial<Record<Counter, number>>
}

export type StorageScrub = {
  /** État du check : new, up, grace, down, paused ; unavailable = Healthchecks injoignable */
  status: string
  at: string | null
  running: boolean
}

type Counter = 'reallocated' | 'pending' | 'uncorrectable' | 'crc'

const COUNTERS: Record<Counter, string> = {
  reallocated: 'réalloués',
  pending: 'en attente',
  uncorrectable: 'illisibles',
  crc: 'erreurs CRC',
}

/** Au-delà, un disque mécanique chauffe trop. */
const HOT = 50

const RING: Record<Tone, string> = {
  ok: 'text-emerald-600 dark:text-emerald-400',
  warn: 'text-amber-500 dark:text-amber-400',
  down: 'text-rose-600 dark:text-rose-400',
  unknown: 'text-zinc-400',
}

const HEALTH: Record<string, { tone: Tone; label: string }> = {
  ONLINE: { tone: 'ok', label: 'Sain' },
  DEGRADED: { tone: 'down', label: 'Dégradé' },
}

const size = (gb: number) => formatGiB(gb).join(' ')

/** « +150 Go/mois », « −60 Go/mois » ; moins de 1 Go par mois : « stable ». */
export function formatRate(perDay: number): string {
  const perMonth = perDay * 30
  if (Math.abs(perMonth) < 1) return 'stable'
  return `${perMonth > 0 ? '+' : '−'}${size(Math.abs(perMonth))}/mois`
}

/** Délai avant que le volume soit plein, arrondi à l'unité qui parle. */
export function formatFullIn(days: number): string {
  if (days < 60) return `dans ~${Math.round(days)} jours`
  if (days < 730) return `dans ~${Math.round(days / 30.44)} mois`
  if (days <= 3650) return `dans ~${Math.round(days / 365.25)} ans`
  return 'dans plus de 10 ans'
}

/** « 8 j », « 3 mois », « 4,6 ans » : âge d'un disque d'après ses heures de fonctionnement. */
export function formatAge(hours: number): string {
  const days = hours / 24
  if (days < 2) return `${hours} h`
  if (days < 60) return `${Math.floor(days)} j`
  if (days < 730) return `${Math.floor(days / 30.44)} mois`
  return `${formatNumber(Math.round((days / 365.25) * 10) / 10)} ans`
}

/** Référence du modèle sans le fabricant ni la révision : « WDC WD20SPZX-08UA7 » → « WD20SPZX ». */
export function shortModel(model: string): string {
  return model.replace(/^WDC /, '').replace(/^(\S+)-\S+$/, '$1')
}

const nonZero = (disk: StorageDisk) => (Object.entries(disk.counters) as [Counter, number][]).filter(([, v]) => v > 0)

export function smartTone(disk: StorageDisk): Tone {
  if (disk.state === 'MISSING' || disk.state === 'FAILED') return 'down'
  if (disk.state !== 'PASSED') return 'unknown'
  if (nonZero(disk).length > 0 || (disk.temperature ?? 0) >= HOT) return 'warn'
  return 'ok'
}

const shortDay = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' })

/** État du check → pastille, mot court affiché, phrase du survol. */
const SCRUB: Record<string, { tone: Tone; short: string; long: string }> = {
  up: { tone: 'ok', short: 'OK', long: 'sans erreur' },
  grace: { tone: 'warn', short: 'en retard', long: 'en retard' },
  down: { tone: 'down', short: 'échec', long: 'en échec' },
  paused: { tone: 'unknown', short: 'en pause', long: 'en pause' },
}

export const StorageCard: CardComponent<StorageProps, StorageData> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null
  const title = card.title ?? card.props.title

  if (data === null) {
    return (
      <div aria-busy="true" className="flex h-full gap-4">
        <div className="size-16 animate-pulse rounded-full bg-zinc-100 dark:bg-slate-800" />
        <div className="flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      </div>
    )
  }

  const percent = data.percent ?? 0
  const tone: Tone = data.percent !== null ? diskTone(percent) : 'unknown'
  const { growth } = data

  return (
    <div className="@container flex h-full flex-col gap-2 tabular-nums">
      <header className="flex items-center gap-2">
        <span className="grid size-7 shrink-0 place-items-center rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
          <DatabaseIcon className="size-4" />
        </span>
        <h3 className="truncate font-semibold text-zinc-900 dark:text-slate-50">{title}</h3>
        {data.health !== null && <PoolHealth health={data.health} />}
        <span className="flex-1" />
        <span className="shrink-0 text-xs text-zinc-500 dark:text-slate-400">
          {growth === null ? (
            'tendance après 2 jours de mesures'
          ) : (
            <span title={`Sur les ${growth.days} derniers jours`}>
              <span className="font-semibold text-zinc-700 dark:text-slate-200">{formatRate(growth.perDay)}</span>
              {growth.fullInDays !== null && ` · plein ${formatFullIn(growth.fullInDays)}`}
            </span>
          )}
        </span>
      </header>

      <div className="grid min-h-0 flex-1 grid-cols-[auto_1fr] gap-4">
        <div className="flex items-center gap-3">
          <RingGauge value={percent / 100} label={`${title} : ${formatNumber(percent)} % utilisés`} className={`size-16 ${RING[tone]}`}>
            <span className="text-sm font-semibold text-zinc-900 dark:text-slate-50">{Math.round(percent)} %</span>
          </RingGauge>
          {data.used !== null && data.total !== null && data.free !== null && (
            <div className="flex flex-col text-xs">
              <span className="font-semibold text-zinc-900 dark:text-slate-50">{formatUsage(data.used, data.total)}</span>
              <span className="text-zinc-500 dark:text-slate-400">{size(data.free)} libres</span>
              {data.scrub !== null && <ScrubLine scrub={data.scrub} />}
            </div>
          )}
        </div>
        <div className="flex min-w-0 flex-col justify-center gap-2">
          {data.disks.length > 0 && (
            <ul className="flex flex-col gap-1 text-xs">
              {data.disks.map((disk) => (
                <DiskRow key={disk.serial} disk={disk} />
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>
  )
}

function PoolHealth({ health }: { health: string }) {
  const { tone, label } = HEALTH[health] ?? { tone: 'unknown' as Tone, label: 'État inconnu' }
  return <StatusPill tone={tone} label={label} />
}

function Dot({ tone }: { tone: Tone }) {
  return <span className={`size-2 shrink-0 rounded-full ${toneDot[tone]}`} aria-hidden="true" />
}

function DiskRow({ disk }: { disk: StorageDisk }) {
  const tone = smartTone(disk)
  const issues = nonZero(disk)
  const details = [disk.model, disk.serial, `SMART ${disk.state}`, ...(Object.keys(disk.counters) as Counter[]).map((c) => `${COUNTERS[c]} : ${disk.counters[c]}`)]

  if (disk.state === 'MISSING') {
    return (
      <li className="flex items-center gap-2 text-rose-700 dark:text-rose-300" title={`${disk.serial} : absent des disques suivis par Beszel`}>
        <Dot tone={tone} />
        <span className="truncate">Disque {disk.serial} absent</span>
      </li>
    )
  }

  return (
    <li className="flex items-center gap-2" title={details.filter(Boolean).join(' · ')}>
      <Dot tone={tone} />
      <span className="w-10 shrink-0 font-semibold text-zinc-900 dark:text-slate-50">{disk.capacity !== null ? formatBytes(disk.capacity) : '?'}</span>
      <span className="min-w-0 flex-1 truncate text-zinc-600 dark:text-slate-300">
        {disk.model !== null ? shortModel(disk.model) : disk.serial}
        {disk.state === 'FAILED' && <span className="ml-1.5 font-semibold text-rose-700 dark:text-rose-300">SMART en échec</span>}
        {issues.map(([c, v]) => (
          <span key={c} className="ml-1.5 font-semibold text-amber-700 dark:text-amber-300">
            {v} {COUNTERS[c]}
          </span>
        ))}
      </span>
      {disk.temperature !== null && (
        <span className={`shrink-0 ${disk.temperature >= HOT ? 'font-semibold text-amber-700 dark:text-amber-300' : 'text-zinc-500 dark:text-slate-400'}`}>
          {disk.temperature} °C
        </span>
      )}
      {disk.hours !== null && (
        <span className="hidden w-12 shrink-0 text-right text-zinc-500 @md:inline dark:text-slate-400" title={`${formatNumber(disk.hours)} h de fonctionnement`}>
          {formatAge(disk.hours)}
        </span>
      )}
    </li>
  )
}

function ScrubLine({ scrub }: { scrub: StorageScrub }) {
  const day = scrub.at !== null ? shortDay.format(new Date(scrub.at)) : null
  let tone: Tone
  let label: string
  let long: string
  if (scrub.status === 'unavailable') {
    ;[tone, label, long] = ['unknown', 'Scrub : ?', 'Scrub : état indisponible (Healthchecks injoignable)']
  } else if (scrub.running) {
    ;[tone, label, long] = ['ok', 'Scrub en cours', `Scrub en cours depuis le ${day}`]
  } else if (scrub.status === 'new' || day === null) {
    ;[tone, label, long] = ['unknown', 'Scrub : jamais', 'Scrub jamais lancé']
  } else {
    const state = SCRUB[scrub.status] ?? { tone: 'unknown' as Tone, short: scrub.status, long: scrub.status }
    ;[tone, label, long] = [state.tone, `Scrub ${day} · ${state.short}`, `Dernier scrub : ${day} · ${state.long}`]
  }

  return (
    <span className="mt-1 flex items-center gap-1.5 whitespace-nowrap text-zinc-500 dark:text-slate-400" title={`${long}. Relecture complète du pool et vérification des sommes de contrôle.`}>
      <Dot tone={tone} />
      {label}
    </span>
  )
}
