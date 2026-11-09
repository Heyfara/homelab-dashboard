import type { CardComponent } from './registry'
import { diskTone, formatGiB, formatUsage } from './MachineCard'
import { formatNumber } from '../ui/format'
import { DatabaseIcon } from '../ui/icons'
import { RingGauge } from '../ui/RingGauge'
import type { Tone } from '../ui/StatusPill'
import { TimeSeries } from '../ui/TimeSeries'

/** Props de StorageCard::describe() côté PHP. */
export type StorageProps = { title: string; system: string }

/** Données de StorageCard::fetch() côté PHP (Go binaires, comme Beszel). */
export type StorageData = {
  percent: number | null
  used: number | null
  total: number | null
  free: number | null
  /** Droite des moindres carrés sur la période ; null tant que le recul manque. */
  growth: { perDay: number; fullInDays: number | null; days: number } | null
  series: { at: string[]; used: (number | null)[] }
}

const RING: Record<Tone, string> = {
  ok: 'text-emerald-600 dark:text-emerald-400',
  warn: 'text-amber-500 dark:text-amber-400',
  down: 'text-rose-600 dark:text-rose-400',
  unknown: 'text-zinc-400',
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
    <div className="flex h-full flex-col gap-2 tabular-nums">
      <header className="flex items-center gap-2">
        <span className="grid size-7 shrink-0 place-items-center rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
          <DatabaseIcon className="size-4" />
        </span>
        <h3 className="truncate font-semibold text-zinc-900 dark:text-slate-50">{title}</h3>
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
            </div>
          )}
        </div>
        {/* Échelle = capacité totale : la courbe se lit comme un niveau de remplissage. */}
        <div className="relative min-h-6 rounded-lg border border-zinc-200 bg-zinc-50 dark:border-slate-800 dark:bg-slate-950/70">
          <TimeSeries
            label="Utilisé"
            at={data.series.at}
            series={[{ label: 'Utilisé', values: data.series.used, className: 'text-indigo-600 dark:text-indigo-400', format: size }]}
            max={data.total ?? undefined}
            clipClassName="rounded-lg"
          />
        </div>
      </div>
    </div>
  )
}
