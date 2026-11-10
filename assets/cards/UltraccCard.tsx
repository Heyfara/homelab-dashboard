import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { formatBytes, formatNumber } from '../ui/format'
import { RingGauge } from '../ui/RingGauge'
import { ServiceIcon } from '../ui/ServiceIcon'
import type { Tone } from '../ui/StatusPill'
import type { ReactNode } from 'react'

/** Props de UltraccCard::describe() côté PHP. */
export type UltraccProps = { title: string; link: string; icon: string | null }

/** Données de UltraccCard::fetch() côté PHP. Stockage en octets, trafic en Go (option traffic_quota). */
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
 * Sans projection (1er jour), le message est null : la carte montre la date.
 */
export function trafficOutlook(percent: number, projected: number | null, reset: string): { tone: Tone; label: string | null } {
  if (percent >= 100) return { tone: 'down', label: `bridé jusqu’au ${reset}` }
  if (projected === null) return { tone: percent >= 85 ? 'warn' : 'ok', label: null }
  const label = `≈ ${Math.round(projected)} % au ${reset}`
  if (projected > 100) return { tone: 'down', label: `≈ ${Math.round(projected)} % : bridage` }
  return { tone: projected >= 85 ? 'warn' : 'ok', label }
}

/** Octets en unités décimales, comme le panneau d'Ultra.cc : « 490 Go / 2 To », « 1,2 / 2 To ». */
export function formatDecimalUsage(used: number, total: number): string {
  const [u, uu] = formatBytes(used).split(' ')
  const [q, qu] = formatBytes(total).split(' ')
  return uu === qu ? `${u} / ${q} ${qu}` : `${u} ${uu} / ${q} ${qu}`
}

/** Le stockage plein bloque les téléchargements : ambre dès 85 %, rouge dès 95 %. */
const storageTone = (percent: number): Tone => (percent >= 95 ? 'down' : percent >= 85 ? 'warn' : 'ok')

export const UltraccCard: CardComponent<UltraccProps, UltraccData> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null
  const { title, link, icon } = card.props

  return (
    <div className="flex h-full flex-col gap-3 tabular-nums">
      <header className="flex items-center gap-2">
        <span className="size-6 shrink-0 [&>*]:size-6 [&>span]:rounded-md [&>span]:text-xs">
          <ServiceIcon icon={icon} name={title} />
        </span>
        <h3 className="min-w-0 flex-1 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        <ExternalLink href={link} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir Ultra.cc →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="grid min-h-0 flex-1 grid-cols-2 gap-3">
          <div className="flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
          <div className="flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
        </div>
      ) : (
        <div className="grid min-h-0 flex-1 grid-cols-2 items-center gap-3">
          <Gauge name="Stockage" percent={data.storage.percent ?? 0} tone={data.storage.percent === null ? 'unknown' : storageTone(data.storage.percent)}>
            <span className="truncate font-semibold text-zinc-900 dark:text-slate-50">{formatDecimalUsage(data.storage.used, data.storage.total)}</span>
            <span className="truncate text-zinc-500 dark:text-slate-400">{formatBytes(data.storage.free)} libres</span>
          </Gauge>
          <Traffic traffic={data.traffic} />
        </div>
      )}
    </div>
  )
}

function Traffic({ traffic }: { traffic: UltraccData['traffic'] }) {
  const reset = day.format(new Date(traffic.nextReset))
  const outlook = trafficOutlook(traffic.percent, traffic.projectedPercent, reset)

  return (
    <Gauge name="Trafic du mois" short="Trafic" percent={traffic.percent} tone={outlook.tone}>
      {traffic.used !== null && traffic.quota !== null && (
        <span className="truncate font-semibold text-zinc-900 dark:text-slate-50">
          {formatDecimalUsage(traffic.used * 1e9, traffic.quota * 1e9)}
        </span>
      )}
      <span className={`truncate ${LABEL[outlook.tone]}`} title={`Remise à zéro le ${reset}`}>
        {outlook.label ?? `remise à zéro le ${reset}`}
      </span>
    </Gauge>
  )
}

function Gauge({ name, short = name, percent, tone, children }: { name: string; short?: string; percent: number; tone: Tone; children: ReactNode }) {
  return (
    <section role="group" aria-label={name} className="flex min-w-0 items-center gap-2.5">
      <RingGauge value={percent / 100} label={`${short} : ${pct(percent)} utilisés`} className={`size-12 ${RING[tone]}`}>
        <span className="text-xs font-semibold text-zinc-900 dark:text-slate-50">{Math.round(percent)} %</span>
      </RingGauge>
      <div className="flex min-w-0 flex-col text-xs">
        <span className="text-[0.65rem] font-semibold tracking-wide text-zinc-400 uppercase dark:text-slate-500">{name}</span>
        {children}
      </div>
    </section>
  )
}
