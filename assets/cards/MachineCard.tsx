import type { ReactNode } from 'react'
import type { CardComponent } from './registry'
import { formatDuration, formatNumber, formatSpeed } from '../ui/format'
import { CpuIcon, MemoryIcon, NetworkIcon, ServerIcon, ThermometerIcon } from '../ui/icons'
import { RingGauge } from '../ui/RingGauge'
import { StatusPill, type Tone } from '../ui/StatusPill'
import { TimeSeries, type Series } from '../ui/TimeSeries'

/** Props de MachineCard::describe() côté PHP. */
export type MachineProps = { title: string; system: string }

export type Disk = { name: string; percent: number | null; used: number | null; total: number | null }

/** Données de MachineCard::fetch() côté PHP (valeurs de Beszel : %, Go binaires, octets/s, °C). */
export type MachineData = {
  machine: { name: string; ip: string; status: 'up' | 'down' | 'paused' | 'pending' | string; uptime: number | null; agent: string | null }
  now: { cpu: number | null; memory: number | null; load: number[]; temperature: number | null }
  memory: { used: number | null; total: number | null }
  disks: Disk[]
  series: {
    at: string[]
    cpu?: (number | null)[]
    memory?: (number | null)[]
    netSent?: (number | null)[]
    netReceived?: (number | null)[]
    temperature?: (number | null)[]
  }
}

const STATUS: Record<string, { tone: Tone; label: string }> = {
  up: { tone: 'ok', label: 'En ligne' },
  down: { tone: 'down', label: 'Hors ligne' },
  paused: { tone: 'warn', label: 'En pause' },
  pending: { tone: 'unknown', label: 'En attente' },
}

/** Un disque plein est un état : vert, puis ambre dès 75 %, rouge dès 90 %. */
export function diskTone(percent: number): Tone {
  return percent >= 90 ? 'down' : percent >= 75 ? 'warn' : 'ok'
}

const RING: Record<Tone, string> = {
  ok: 'text-emerald-600 dark:text-emerald-400',
  warn: 'text-amber-500 dark:text-amber-400',
  down: 'text-rose-600 dark:text-rose-400',
  unknown: 'text-zinc-400',
}

const pct = (v: number) => `${formatNumber(v)} %`
const celsius = (v: number) => `${formatNumber(Math.round(v))} °C`

/** Tailles telles que Beszel les donne (Go binaires) : 1,7 To au-delà de 1000 Go. */
function formatGiB(gb: number): [string, 'Go' | 'To'] {
  return gb >= 1000 ? [formatNumber(gb / 1024), 'To'] : [formatNumber(gb), 'Go']
}

/** « 4,9 / 15,4 Go », « 142,9 Go / 1,8 To » : l'unité une seule fois si elle est commune. */
export function formatUsage(used: number, total: number): string {
  const [u, uu] = formatGiB(used)
  const [t, tu] = formatGiB(total)
  return uu === tu ? `${u} / ${t} ${tu}` : `${u} ${uu} / ${t} ${tu}`
}

export const MachineCard: CardComponent<MachineProps, MachineData> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null
  const title = card.title ?? card.props.title

  if (data === null) {
    return (
      <div aria-busy="true" className="flex h-full flex-col gap-3">
        <div className="h-6 w-2/3 animate-pulse rounded bg-zinc-100 dark:bg-slate-800" />
        <div className="grid min-h-0 flex-1 grid-cols-[4rem_1fr] gap-4">
          <div className="animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
          <div className="animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
        </div>
      </div>
    )
  }

  const { machine, now, series } = data
  const status = STATUS[machine.status] ?? { tone: 'unknown' as Tone, label: machine.status }

  return (
    <div className="flex h-full flex-col gap-3 tabular-nums">
      <header className="flex items-center gap-2">
        <span className="grid size-7 shrink-0 place-items-center rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
          <ServerIcon className="size-4" />
        </span>
        <h3 className="truncate font-semibold text-zinc-900 capitalize dark:text-slate-50">{title}</h3>
        <span className="shrink-0 font-mono text-xs text-zinc-500 dark:text-slate-400">{machine.ip}</span>
        <StatusPill tone={status.tone} label={status.label} />
        <span className="flex-1" />
        <span className="flex shrink-0 items-center gap-3 text-xs text-zinc-500 dark:text-slate-400">
          {now.temperature !== null && (
            <span className="flex items-center gap-0.5" title="Température">
              <ThermometerIcon className="size-3.5 text-orange-500" />
              {celsius(now.temperature)}
            </span>
          )}
          {machine.uptime !== null && <span title="Temps depuis le dernier démarrage">depuis {formatDuration(machine.uptime)}</span>}
        </span>
      </header>

      <div className="grid min-h-0 flex-1 grid-cols-[auto_1fr] gap-5">
        <ul className="flex flex-col gap-3">
          {data.disks.map((disk) => (
            <DiskGauge key={disk.name} disk={disk} />
          ))}
        </ul>
        {/* Processeur et Mémoire côte à côte, Réseau (deux séries) sur toute la largeur. */}
        <div className="grid min-h-0 min-w-0 auto-rows-fr grid-cols-2 gap-2 [&>[data-wide]]:col-span-2">
          {series.cpu && (
            <Chart
              label="Processeur"
              icon={<CpuIcon className="size-3.5 text-indigo-600 dark:text-indigo-400" />}
              value={now.cpu !== null ? pct(now.cpu) : '—'}
              detail={now.load.length ? `charge ${now.load.map((l) => formatNumber(l)).join(' · ')}` : undefined}
              at={series.at}
              max={100}
              series={[{ label: 'Processeur', values: series.cpu, className: 'text-indigo-600 dark:text-indigo-400', format: pct }]}
            />
          )}
          {series.memory && (
            <Chart
              label="Mémoire"
              icon={<MemoryIcon className="size-3.5 text-teal-600 dark:text-teal-400" />}
              value={now.memory !== null ? pct(now.memory) : '—'}
              detail={data.memory.used !== null && data.memory.total !== null ? formatUsage(data.memory.used, data.memory.total) : undefined}
              at={series.at}
              max={100}
              series={[{ label: 'Mémoire', values: series.memory, className: 'text-teal-600 dark:text-teal-400', format: pct }]}
            />
          )}
          {series.netSent && series.netReceived && (
            <Chart
              wide
              label="Réseau"
              icon={<NetworkIcon className="size-3.5 text-zinc-500 dark:text-slate-400" />}
              value={
                <span className="flex items-center gap-2.5 text-xs">
                  <Keyed label="Envoyé" className="text-up">
                    ↑ {formatSpeed(lastOf(series.netSent))}
                  </Keyed>
                  <Keyed label="Reçu" className="text-down">
                    ↓ {formatSpeed(lastOf(series.netReceived))}
                  </Keyed>
                </span>
              }
              at={series.at}
              series={[
                { label: 'Envoyé', values: series.netSent, className: 'text-up', format: formatSpeed },
                { label: 'Reçu', values: series.netReceived, className: 'text-down', format: formatSpeed },
              ]}
            />
          )}
          {series.temperature && (
            <Chart
              label="Température"
              icon={<ThermometerIcon className="size-3.5 text-orange-600 dark:text-orange-400" />}
              value={now.temperature !== null ? celsius(now.temperature) : '—'}
              at={series.at}
              series={[{ label: 'Température', values: series.temperature, className: 'text-orange-600 dark:text-orange-400', format: celsius }]}
            />
          )}
        </div>
      </div>
    </div>
  )
}

function DiskGauge({ disk }: { disk: Disk }) {
  const percent = disk.percent ?? 0
  const tone = disk.percent !== null ? diskTone(percent) : 'unknown'

  return (
    <li className="flex flex-col items-center gap-1 text-center">
      <RingGauge value={percent / 100} label={`Disque ${disk.name} : ${pct(percent)}`} className={`size-14 ${RING[tone]}`}>
        <span className="text-xs font-semibold text-zinc-900 dark:text-slate-50">{Math.round(percent)} %</span>
      </RingGauge>
      <span className="max-w-20 truncate text-xs font-medium text-zinc-700 dark:text-slate-200">{disk.name}</span>
      {disk.used !== null && disk.total !== null && (
        <span className="text-[0.65rem] text-zinc-500 dark:text-slate-400">
          {formatUsage(disk.used, disk.total)}
        </span>
      )}
    </li>
  )
}

function Chart(props: { label: string; icon: ReactNode; value: ReactNode; detail?: string; at: string[]; series: Series[]; max?: number; wide?: boolean }) {
  // Panneau plus sombre que la carte ; la courbe va jusqu'à ses bords.
  return (
    <div
      role="group"
      aria-label={props.label}
      data-wide={props.wide ? '' : undefined}
      className="flex min-h-0 flex-col rounded-lg border border-zinc-200 bg-zinc-50 dark:border-slate-800 dark:bg-slate-950/70"
    >
      <div className="px-2.5 pt-1.5">
        <p className="flex items-center gap-1.5">
          {props.icon}
          <span className="text-[0.65rem] font-semibold tracking-wider text-zinc-500 uppercase dark:text-slate-400">{props.label}</span>
          <span className="ml-auto truncate text-sm font-bold text-zinc-900 dark:text-slate-50">{props.value}</span>
        </p>
        {props.detail && <p className="truncate text-[0.65rem] text-zinc-500 dark:text-slate-400">{props.detail}</p>}
      </div>
      <div className="relative min-h-6 flex-1">
        <TimeSeries label={props.label} at={props.at} series={props.series} max={props.max} clipClassName="rounded-b-lg" />
      </div>
    </div>
  )
}

/** Valeur précédée du trait de couleur de sa série (légende d'un graphique à plusieurs séries). */
function Keyed({ label, className, children }: { label: string; className: string; children: ReactNode }) {
  return (
    <span className="flex items-center gap-1" title={label}>
      <span data-key={label} className={`h-0.5 w-3 rounded-full bg-current ${className}`} aria-hidden="true" />
      <span className="sr-only">{label} : </span>
      {children}
    </span>
  )
}

function lastOf(values: (number | null)[]): number {
  return [...values].reverse().find((v): v is number => v !== null) ?? 0
}
