import { useState, type ReactNode } from 'react'
import type { CardComponent } from './registry'
import { useSampleHistory } from '../hooks/useSampleHistory'
import { formatRelative, formatSpeed } from '../ui/format'
import { AlertIcon, ArrowDownIcon, ArrowUpIcon, NetworkIcon, ServerIcon } from '../ui/icons'
import { Sparkline } from '../ui/Sparkline'
import { DeviceDetail } from './network/DeviceDetail'
import { TopologyDiagram } from './network/TopologyDiagram'
import { bandLabel, countDevices, countZones, deviceLabel, filterDevices, ipMismatch, isUnknown, placeLabel, TYPE_LABELS, UNCERTAIN, weakSignal, type Device, type DeviceFilter } from './network/devices'

/** Props de NetworkCard::describe() côté PHP. */
export type NetworkProps = { title: string }

/** Point d'accès lu par SSH ; `error` s'il n'a pas répondu. */
/** `device` : MAC de l'appareil du point d'accès lui-même, s'il est dans la liste. */
export type AccessPointInfo = { id: string; zone: string; error: string | null; uplink: string; radios: Record<string, string>; device: string | null }

/** Données de NetworkCard::fetch() côté PHP. */
export type NetworkData = { devices: Device[]; accessPoints: AccessPointInfo[]; wiredZone: string }

/** Débit d'un lien, vu de l'appareil branché : `down` = vers lui. Octets par seconde. */
export type LinkRate = { down: number; up: number }

/** Flux `rates` (NetworkCard::fetchFeed). Débits en octets par seconde. */
export type NetworkRates = {
  wan: { state: string; media: string; down: number; up: number; maxDown: number; maxUp: number }
  /** Par point d'accès ; `ports` vide au premier relevé (il en faut deux) */
  accessPoints: Record<string, { error: string | null; ports: Record<string, LinkRate> }>
}

/** Mesures gardées pour les courbes : 5 min au rythme de 5 s. */
const HISTORY = 60

const STATES: Record<string, string> = { up: 'Connectée', down: 'Coupée', going_up: 'Connexion…', going_down: 'Déconnexion…' }
const MEDIA: Record<string, string> = { ftth: 'Fibre', xdsl: 'ADSL / VDSL', backup_4g: 'Secours 4G' }

/** Débit d'une ligne, en bits comme les offres des opérateurs : 1 Gb/s, 700 Mb/s. */
function formatLineRate(bytesPerSecond: number): string {
  const bits = bytesPerSecond * 8
  const [value, unit] = bits >= 1e9 ? [bits / 1e9, 'Gb/s'] : [bits / 1e6, 'Mb/s']
  return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(value)} ${unit}`
}

const FILTERS: { key: DeviceFilter; label: string }[] = [
  { key: 'all', label: 'Tous' },
  { key: 'online', label: 'En ligne' },
  { key: 'unknown', label: 'Sans nom' },
]

export const NetworkCard: CardComponent<NetworkProps, NetworkData> = ({ card, result, feeds }) => {
  const data = result?.status === 'ok' ? result.data : null
  const ratesResult = feeds?.rates ?? null
  const wan = ratesResult?.status === 'ok' ? (ratesResult.data as NetworkRates).wan : null
  const history = useSampleHistory(wan ? ratesResult!.fetchedAt : null, wan ? { down: wan.down, up: wan.up } : null, HISTORY)
  const [filter, setFilter] = useState<DeviceFilter>('all')
  const [zone, setZone] = useState<string | null>(null)
  /** Nœud choisi sur le schéma ou dans la liste : MAC, ou internet / box / switch */
  const [selected, setSelected] = useState<string | null>(null)
  const rates = ratesResult?.status === 'ok' ? (ratesResult.data as NetworkRates) : null
  const portRates = ratesResult?.status === 'ok' ? (ratesResult.data as NetworkRates).accessPoints : {}

  if (data === null) {
    return (
      <div aria-busy="true" className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="h-20 animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
          ))}
        </div>
        <div className="h-64 animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
      </div>
    )
  }

  const counts = countDevices(data.devices)
  const mismatches = data.devices.filter(ipMismatch)
  const shown = filterDevices(data.devices, filter, zone)
  const zones = countZones(data.devices, [...data.accessPoints.map((ap) => ap.zone), data.wiredZone])

  return (
    <div className="flex flex-col gap-4">
      <header className="flex items-center gap-2">
        <NetworkIcon className="size-5 shrink-0 text-zinc-500 dark:text-slate-400" />
        <h3 className="min-w-0 flex-1 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? card.props.title}</h3>
      </header>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Tile label="Internet" busy={wan === null} icon={<NetworkIcon className="size-4" />} tone="neutral">
          {wan && (
            <p className="text-base font-semibold text-zinc-900 dark:text-slate-50">
              {STATES[wan.state] ?? wan.state}
              {wan.media !== '' && <span className="font-normal text-zinc-500 dark:text-slate-400"> · {MEDIA[wan.media] ?? wan.media}</span>}
            </p>
          )}
        </Tile>
        <RateTile label="Descendant" rate={wan?.down ?? null} max={wan?.maxDown ?? null} history={history.map((h) => h.down)} tone="down" />
        <RateTile label="Montant" rate={wan?.up ?? null} max={wan?.maxUp ?? null} history={history.map((h) => h.up)} tone="up" />
        <Tile label="Appareils" icon={<ServerIcon className="size-4" />} tone="neutral">
          <p className="text-base font-semibold text-zinc-900 tabular-nums dark:text-slate-50">
            {counts.online} en ligne <span className="font-normal text-zinc-500 dark:text-slate-400">sur {counts.all}</span>
          </p>
        </Tile>
      </div>

      {(mismatches.length > 0 || counts.unknown > 0) && (
        <section aria-label="À vérifier" className="flex flex-col gap-1 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
          {mismatches.map((d) => (
            <p key={d.mac} className="flex items-start gap-2">
              <AlertIcon className="mt-0.5 size-4 shrink-0" />
              {deviceLabel(d)} a l’adresse {d.ip} au lieu de {d.expectedIp} (bail statique)
            </p>
          ))}
          {counts.unknown > 0 && (
            <p className="flex items-start gap-2">
              <AlertIcon className="mt-0.5 size-4 shrink-0" />
              {counts.unknown} appareil{counts.unknown > 1 ? 's' : ''} sans nom : à identifier (filtre « Sans nom »)
            </p>
          )}
        </section>
      )}

      {data.accessPoints.length > 0 && (
        <section aria-label="Points d’accès" className="grid grid-cols-1 gap-3 md:grid-cols-2">
          {data.accessPoints.map((ap) => (
            <AccessPointPanel key={ap.id} ap={ap} devices={data.devices} rates={portRates[ap.id]?.ports ?? {}} />
          ))}
        </section>
      )}

      <section aria-label="Schéma du réseau" className="flex flex-col gap-2">
        <Legend />
        <TopologyDiagram data={data} rates={rates} selected={selected} onSelect={setSelected} />
      </section>

      <div className="flex flex-col-reverse gap-4 lg:flex-row lg:items-start">
      <section aria-label="Appareils" className="flex min-w-0 flex-1 flex-col gap-2">
        <div className="flex flex-wrap gap-x-4 gap-y-2">
          <div role="group" aria-label="Filtrer les appareils" className="flex flex-wrap gap-1.5">
            {FILTERS.map((f) => (
              <Chip key={f.key} pressed={filter === f.key} onClick={() => setFilter(f.key)} label={f.label} count={counts[f.key]} />
            ))}
          </div>
          {zones.length > 1 && (
            <div role="group" aria-label="Filtrer par position" className="flex flex-wrap gap-1.5">
              <Chip pressed={zone === null} onClick={() => setZone(null)} label="Toutes" />
              {zones.map((z) => (
                <Chip key={z.zone} pressed={zone === z.zone} onClick={() => setZone(z.zone)} label={z.zone === UNCERTAIN ? 'Incertaine' : z.zone} count={z.count} />
              ))}
            </div>
          )}
        </div>
        <ul className="divide-y divide-zinc-100 dark:divide-slate-800">
          {shown.map((d) => (
            <DeviceRow key={d.mac} device={d} selected={selected === d.mac} onSelect={() => setSelected(d.mac)} />
          ))}
        </ul>
      </section>
      <div className="lg:sticky lg:top-4 lg:w-80 lg:shrink-0">
        <DeviceDetail selected={selected} data={data} rates={rates} />
      </div>
      </div>
    </div>
  )
}

function Legend() {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-slate-400">
      <span className="flex items-center gap-1.5">
        <svg width="24" height="6" aria-hidden="true">
          <path d="M1 3h22" className="stroke-zinc-400" strokeWidth="2" />
        </svg>
        câble
      </span>
      <span className="flex items-center gap-1.5">
        <svg width="24" height="6" aria-hidden="true">
          <path d="M1 3h22" className="stroke-zinc-400" strokeWidth="2" strokeDasharray="4 5" />
        </svg>
        Wi-Fi
      </span>
      <span className="flex items-center gap-1.5">
        <span className="size-2 rounded-full bg-down" /> descendant
      </span>
      <span className="flex items-center gap-1.5">
        <span className="size-1.5 rounded-full bg-zinc-700 dark:bg-slate-100" /> montant
      </span>
      <span>points animés : débit mesuré</span>
    </div>
  )
}

function Chip({ pressed, onClick, label, count }: { pressed: boolean; onClick: () => void; label: string; count?: number }) {
  return (
    <button
      type="button"
      aria-pressed={pressed}
      onClick={onClick}
      className="rounded-full px-3 py-1 text-sm ring-1 ring-zinc-200 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-indigo-500 aria-pressed:bg-indigo-600 aria-pressed:text-white aria-pressed:ring-indigo-600 dark:ring-slate-700 dark:hover:bg-slate-800 dark:aria-pressed:bg-indigo-500"
    >
      {label} {count !== undefined && <span className="tabular-nums opacity-70">{count}</span>}
    </button>
  )
}

/** Un point d'accès : lien vers le switch, radios, puis ports câblés utilisés. */
function AccessPointPanel({ ap, devices, rates }: { ap: AccessPointInfo; devices: Device[]; rates: Record<string, LinkRate> }) {
  const on = (port: string) => devices.filter((d) => d.online && d.place?.ap === ap.id && d.place.port === port)
  const radios = Object.entries(ap.radios).sort(([, a], [, b]) => Number(a) - Number(b))
  const cables = [...new Set([...Object.keys(rates), ...devices.filter((d) => d.place?.ap === ap.id && d.place.link === 'cable').map((d) => d.place!.port!)])]
    .filter((port) => port !== ap.uplink && !(port in ap.radios))
    .filter((port) => on(port).length > 0 || (rates[port] !== undefined && rates[port].down + rates[port].up > 0))
    .sort()

  return (
    <div className="flex flex-col gap-1 rounded-xl bg-zinc-50 p-3 dark:bg-slate-800/60">
      <p className="flex items-baseline gap-2">
        <span className="font-semibold text-zinc-900 dark:text-slate-50">{ap.zone}</span>
        <span className="text-xs text-zinc-500 dark:text-slate-400">{ap.id}</span>
      </p>
      {ap.error !== null ? (
        <p className="flex items-start gap-2 text-sm text-amber-800 dark:text-amber-200">
          <AlertIcon className="mt-0.5 size-4 shrink-0" />
          {ap.error}
        </p>
      ) : (
        <>
          <PortLine label={`Vers le switch (${ap.uplink})`} rate={rates[ap.uplink]} />
          {radios.map(([port, band]) => {
            const n = on(port).length
            return <PortLine key={port} label={`Wi-Fi ${bandLabel(band)}`} detail={`${n} appareil${n > 1 ? 's' : ''}`} rate={rates[port]} />
          })}
          {cables.map((port) => (
            <PortLine key={port} label={`Câble ${port}`} detail={on(port).map(deviceLabel).join(', ')} rate={rates[port]} />
          ))}
        </>
      )}
    </div>
  )
}

function PortLine({ label, detail, rate }: { label: string; detail?: string; rate: LinkRate | undefined }) {
  return (
    <div role="group" aria-label={label} className="flex items-baseline gap-2 text-sm">
      <span className="shrink-0 text-zinc-700 dark:text-slate-200">{label}</span>
      <span className="min-w-0 flex-1 truncate text-xs text-zinc-500 dark:text-slate-400">{detail}</span>
      {rate !== undefined && (
        <span className="shrink-0 text-xs whitespace-nowrap tabular-nums">
          <span className="text-down">↓ {formatSpeed(rate.down)}</span> <span className="text-up">↑ {formatSpeed(rate.up)}</span>
        </span>
      )}
    </div>
  )
}

// Classes écrites en entier : Tailwind ne détecte pas `text-${tone}`.
const TONES = {
  down: { chip: 'bg-down/10 text-down', line: 'text-down' },
  up: { chip: 'bg-up/10 text-up', line: 'text-up' },
  neutral: { chip: 'bg-zinc-200/60 text-zinc-600 dark:bg-slate-700/60 dark:text-slate-300', line: '' },
}

function Tile({ label, icon, tone, busy = false, children }: { label: string; icon: ReactNode; tone: keyof typeof TONES; busy?: boolean; children: ReactNode }) {
  return (
    <div role="group" aria-label={label} aria-busy={busy} className="flex items-center gap-3 rounded-xl bg-zinc-50 p-3 dark:bg-slate-800/60">
      <span className={`grid size-9 shrink-0 place-items-center rounded-lg ${TONES[tone].chip}`}>{icon}</span>
      <div className="min-w-0 flex-1">
        <p className="text-xs text-zinc-500 dark:text-slate-400">{label}</p>
        {busy ? <div className="mt-1 h-5 w-24 animate-pulse rounded bg-zinc-200 dark:bg-slate-700" /> : children}
      </div>
    </div>
  )
}

function RateTile({ label, rate, max, history, tone }: { label: string; rate: number | null; max: number | null; history: number[]; tone: 'down' | 'up' }) {
  const Icon = tone === 'down' ? ArrowDownIcon : ArrowUpIcon

  return (
    <Tile label={label} icon={<Icon className="size-4" />} tone={tone} busy={rate === null}>
      <div className="flex items-center gap-2">
        <div className="min-w-0 shrink-0 tabular-nums">
          <p className="text-base leading-5 font-semibold whitespace-nowrap text-zinc-900 dark:text-slate-50">{formatSpeed(rate ?? 0)}</p>
          {max !== null && max > 0 && <p className="text-xs whitespace-nowrap text-zinc-500 dark:text-slate-400">max. {formatLineRate(max)}</p>}
        </div>
        <div className="h-8 min-w-8 flex-1">
          <Sparkline values={history} label={`Débit ${label.toLowerCase()} sur les dernières minutes, maximum ${formatSpeed(Math.max(0, ...history))}`} className={`h-full ${TONES[tone].line}`} />
        </div>
      </div>
    </Tile>
  )
}

function DeviceRow({ device: d, selected, onSelect }: { device: Device; selected: boolean; onSelect: () => void }) {
  const unknown = isUnknown(d)

  return (
    <li className={`${selected ? 'bg-zinc-100 dark:bg-slate-800' : ''} grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-0.5 py-2 text-sm md:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_8rem]`}>
      <div className="flex min-w-0 items-center gap-2">
        <span aria-hidden="true" className={`size-2 shrink-0 rounded-full ${d.online ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-slate-600'}`} />
        <div className="min-w-0">
          <button
            type="button"
            onClick={onSelect}
            aria-label={`Détail de ${deviceLabel(d)}`}
            className={`block max-w-full truncate text-left hover:underline focus-visible:outline-2 focus-visible:outline-indigo-500 ${unknown ? 'text-amber-700 italic dark:text-amber-300' : 'text-zinc-900 dark:text-slate-100'}`}
            title={deviceLabel(d)}
          >
            {deviceLabel(d)}
          </button>
          <p className="truncate text-xs text-zinc-500 dark:text-slate-400">
            {TYPE_LABELS[d.type]}
            {d.vendor !== null && d.name !== null && ` · ${d.vendor}`}
          </p>
        </div>
      </div>
      <Position device={d} className="hidden md:block" />
      <p className="text-right font-mono text-xs text-zinc-700 md:text-left dark:text-slate-300">
        {d.ip ?? '—'}
        {ipMismatch(d) && (
          <span className="ml-1 text-amber-700 dark:text-amber-300" title={`Bail statique : ${d.expectedIp}`}>
            ≠ {d.expectedIp}
          </span>
        )}
      </p>
      <p className="hidden truncate font-mono text-xs text-zinc-500 md:block dark:text-slate-400">{d.mac}</p>
      <Position device={d} className="col-span-2 md:hidden" />
      <p className="col-span-2 text-xs text-zinc-500 md:col-span-1 md:text-right dark:text-slate-400">
        <span className="font-mono md:hidden">{d.mac} · </span>
        {d.online ? 'En ligne' : d.lastSeen !== null ? `Vu ${formatRelative(d.lastSeen)}` : 'Hors ligne'}
      </p>
    </li>
  )
}

function Position({ device: d, className }: { device: Device; className: string }) {
  const p = d.place
  if (p === null) {
    return <p className={`${className} truncate text-xs text-zinc-500 italic dark:text-slate-400`}>{d.online ? UNCERTAIN : ''}</p>
  }

  return (
    <p className={`${className} truncate text-xs text-zinc-700 dark:text-slate-300`}>
      {placeLabel(p)}
      {p.signal !== null && <span className={weakSignal(p) ? 'text-amber-700 dark:text-amber-300' : 'text-zinc-500 dark:text-slate-400'}> · {p.signal} dBm</span>}
      {p.seenAt !== null && <span className="text-zinc-500 dark:text-slate-400"> · vu {formatRelative(p.seenAt)}</span>}
    </p>
  )
}
