import type { CardComponent } from './registry'
import { formatGiB, formatUsage } from './MachineCard'
import { ExternalLink } from '../ui/ExternalLink'
import { formatBytes, formatNumber } from '../ui/format'
import { RingGauge } from '../ui/RingGauge'
import { ServiceIcon } from '../ui/ServiceIcon'
import type { Tone } from '../ui/StatusPill'
import type { ReactNode } from 'react'

/** Props de UltraccCard::describe() côté PHP. */
export type UltraccProps = { title: string; url: string; icon: string | null }

/** Données de UltraccCard::fetch() côté PHP. Stockage en Gio, trafic en Go (option traffic_quota). */
export type UltraccData = {
  storage: { used: number; total: number; free: number; percent: number | null }
  traffic: {
    percent: number
    used: number | null
    quota: number | null
    lastReset: string
    nextReset: string
    /** Trafic prévu à la remise à zéro au rythme actuel ; null le 1er jour */
    projectedPercent: number | null
  }
}

const RING: Record<Tone, string> = {
  ok: 'text-emerald-600 dark:text-emerald-400',
  warn: 'text-amber-500 dark:text-amber-400',
  down: 'text-rose-600 dark:text-rose-400',
  unknown: 'text-zinc-400',
}

const LABEL: Record<Tone, string> = {
  ok: 'text-zinc-500 dark:text-slate-400',
  warn: 'font-semibold text-amber-600 dark:text-amber-400',
  down: 'font-semibold text-rose-600 dark:text-rose-400',
  unknown: 'text-zinc-500 dark:text-slate-400',
}

const day = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', timeZone: 'UTC' })
const pct = (v: number) => `${formatNumber(v)} %`

/**
 * Couleur et message du trafic : au-delà du quota, Ultra.cc bride le débit.
 * La projection (rythme actuel jusqu'à la remise à zéro) prime sur le consommé.
 */
export function trafficOutlook(percent: number, projected: number | null): { tone: Tone; label: string | null } {
  if (percent >= 100) return { tone: 'down', label: 'quota atteint : débit bridé' }
  if (projected === null) return { tone: percent >= 85 ? 'warn' : 'ok', label: null }
  const label = `≈ ${Math.round(projected)} % à ce rythme`
  if (projected > 100) return { tone: 'down', label: `${label} : bridage probable` }
  return { tone: projected >= 85 ? 'warn' : 'ok', label }
}

/** Trafic en Go décimaux (comme Ultra.cc) : « 490 Go / 2 To », « 1,2 / 2 To ». */
export function formatTrafficUsage(usedGb: number, quotaGb: number): string {
  const [u, uu] = formatBytes(usedGb * 1e9).split(' ')
  const [q, qu] = formatBytes(quotaGb * 1e9).split(' ')
  return uu === qu ? `${u} / ${q} ${qu}` : `${u} ${uu} / ${q} ${qu}`
}

/** Le stockage plein bloque les téléchargements : ambre dès 85 %, rouge dès 95 %. */
const storageTone = (percent: number): Tone => (percent >= 95 ? 'down' : percent >= 85 ? 'warn' : 'ok')

export const UltraccCard: CardComponent<UltraccProps, UltraccData> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null
  const { title, url, icon } = card.props

  return (
    <div className="flex h-full flex-col gap-3 tabular-nums">
      <header className="flex items-center gap-2">
        <span className="size-6 shrink-0 [&>*]:size-6 [&>span]:rounded-md [&>span]:text-xs">
          <ServiceIcon icon={icon} name={title} />
        </span>
        <ExternalLink href={url} className="min-w-0 flex-1 truncate font-semibold text-zinc-900 hover:underline focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-50">
          {card.title ?? title}
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="flex min-h-0 flex-1 flex-col gap-3">
          <div className="flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
          <div className="flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
        </div>
      ) : (
        <div className="flex min-h-0 flex-1 flex-col justify-around gap-3">
          <Gauge name="Stockage" percent={data.storage.percent ?? 0} tone={data.storage.percent === null ? 'unknown' : storageTone(data.storage.percent)}>
            <span className="font-semibold text-zinc-900 dark:text-slate-50">{formatUsage(data.storage.used, data.storage.total)}</span>
            <span className="text-zinc-500 dark:text-slate-400">{formatGiB(data.storage.free).join(' ')} libres</span>
          </Gauge>
          <Traffic traffic={data.traffic} />
        </div>
      )}
    </div>
  )
}

function Traffic({ traffic }: { traffic: UltraccData['traffic'] }) {
  const outlook = trafficOutlook(traffic.percent, traffic.projectedPercent)

  return (
    <Gauge name="Trafic du mois" short="Trafic" percent={traffic.percent} tone={outlook.tone}>
      {traffic.used !== null && traffic.quota !== null && (
        <span className="font-semibold text-zinc-900 dark:text-slate-50">
          {formatTrafficUsage(traffic.used, traffic.quota)}
        </span>
      )}
      <span className="text-zinc-500 dark:text-slate-400">remise à zéro le {day.format(new Date(traffic.nextReset))}</span>
      {outlook.label !== null && <span className={LABEL[outlook.tone]}>{outlook.label}</span>}
    </Gauge>
  )
}

function Gauge({ name, short = name, percent, tone, children }: { name: string; short?: string; percent: number; tone: Tone; children: ReactNode }) {
  return (
    <section role="group" aria-label={name} className="flex items-center gap-3">
      <RingGauge value={percent / 100} label={`${short} : ${pct(percent)} utilisés`} className={`size-14 ${RING[tone]}`}>
        <span className="text-xs font-semibold text-zinc-900 dark:text-slate-50">{Math.round(percent)} %</span>
      </RingGauge>
      <div className="flex min-w-0 flex-col text-xs">
        <span className="text-[0.65rem] font-semibold tracking-wide text-zinc-400 uppercase dark:text-slate-500">{name}</span>
        {children}
      </div>
    </section>
  )
}
