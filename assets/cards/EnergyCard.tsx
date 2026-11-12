import { useState, type ReactNode } from 'react'
import type { CardResult, CardView } from '../http/types'
import { DailyBars } from '../ui/DailyBars'
import { ExternalLink } from '../ui/ExternalLink'
import { formatNumber } from '../ui/format'
import { BoltIcon, HomeIcon, SunIcon } from '../ui/icons'
import { ServiceIcon } from '../ui/ServiceIcon'
import { StatTile } from '../ui/StatTile'
import { TimeSeries, type Series } from '../ui/TimeSeries'

/** Props de EnergyCard::describe() côté PHP. */
export type EnergyProps = { title: string; url: string }

type Pair = { at: string[]; solar: (number | null)[]; grid: (number | null)[] }

/** Données de EnergyCard::fetch() côté PHP : W (ou VA pour le réseau), kWh, €. */
export type EnergyData = {
  now: { solar: number | null; grid: number | null }
  /** €/kWh du réseau, null si le tableau Énergie de HA n'en donne pas */
  price: number | null
  /** null : pas de compteur de cette sorte dans HA ; saved = plafond (l'injection n'est pas mesurée) */
  today: { solar: number | null; grid: number | null; cost: number | null; saved: number | null }
  /** Puissance moyenne de chaque heure (W), 24 dernières heures */
  day: Pair
  /** Énergie de chaque jour (kWh), 30 derniers jours */
  month: Pair
}

/** « 413 W », « 2,8 kW » ; unit = VA pour le compteur Linky (puissance apparente). */
export function formatPower(value: number, unit: 'W' | 'VA' = 'W'): string {
  return value < 1000 ? `${formatNumber(Math.round(value))} ${unit}` : `${formatNumber(Math.round(value / 100) / 10)} k${unit}`
}

const kwh = (value: number) => `${formatNumber(value < 10 ? Math.round(value * 100) / 100 : Math.round(value * 10) / 10)} kWh`
const euros = (value: number) => `${new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} €`
const sum = (...values: (number | null)[]) => (values.every((v) => v === null) ? null : values.reduce<number>((a, v) => a + (v ?? 0), 0))

/** Couleurs validées (contraste, daltonisme) en clair et en sombre. */
const SOLAR = 'text-amber-500 dark:text-amber-600'
const GRID = 'text-indigo-500 dark:text-indigo-500'

type Range = 'day' | 'month'

export function EnergyCard({ card, result }: { card: CardView<EnergyProps>; result: CardResult<EnergyData> | null }) {
  const { title, url } = card.props
  const data = result?.status === 'ok' ? result.data : null
  const [range, setRange] = useState<Range>('day')

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon="sh-home-assistant" name="Home Assistant" />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        <span className="flex-1" />
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="flex min-h-0 flex-1 flex-col gap-3">
          <div className="grid h-16 grid-cols-3 gap-3">
            <div className="animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
            <div className="animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
            <div className="animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
          </div>
          <div className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
        </div>
      ) : (
        <>
          <Tiles data={data} />
          {card.rows > 1 && <Chart data={data} range={range} onRange={setRange} />}
        </>
      )}
    </div>
  )
}

function Tiles({ data }: { data: EnergyData }) {
  const hasSolar = data.today.solar !== null
  const house = data.now.grid !== null ? sum(data.now.grid, data.now.solar) : null

  return (
    <div className={`grid gap-2 ${hasSolar ? 'grid-cols-3' : 'grid-cols-2'}`}>
      {hasSolar && (
        <Tile
          label="Solaire"
          title={`Production des panneaux. Économisé aujourd'hui : au plus ${data.today.saved !== null ? euros(data.today.saved) : '—'}, la part renvoyée au réseau n'étant pas mesurée.`}
          icon={<SunIcon className="size-4" />}
          tone="bg-amber-500/8 [&_[data-chip]]:bg-amber-500/15 [&_[data-chip]]:text-amber-600 dark:[&_[data-chip]]:text-amber-400"
          value={data.now.solar !== null ? formatPower(data.now.solar) : '—'}
          detail={`${kwh(data.today.solar!)}${data.today.saved !== null ? ` · ≤ ${euros(data.today.saved)}` : ''}`}
        />
      )}
      <Tile
        label="Réseau"
        title={`Tiré du réseau (puissance apparente du compteur, en VA).${data.price !== null ? ` Tarif : ${formatNumber(data.price)} €/kWh.` : ''}`}
        icon={<BoltIcon className="size-4" />}
        tone="bg-indigo-500/8 [&_[data-chip]]:bg-indigo-500/15 [&_[data-chip]]:text-indigo-600 dark:[&_[data-chip]]:text-indigo-400"
        value={data.now.grid !== null ? formatPower(data.now.grid, 'VA') : '—'}
        detail={data.today.grid !== null ? `${kwh(data.today.grid)}${data.today.cost !== null ? ` · ${euros(data.today.cost)}` : ''}` : '—'}
      />
      <Tile
        label="Maison"
        title="Consommation estimée : réseau + solaire (le surplus solaire renvoyé au réseau n'est pas mesuré)."
        icon={<HomeIcon className="size-4" />}
        tone="bg-zinc-500/8 [&_[data-chip]]:bg-zinc-500/15 [&_[data-chip]]:text-zinc-600 dark:[&_[data-chip]]:text-slate-300"
        value={house !== null ? `≈ ${formatPower(house)}` : '—'}
        detail={(() => {
          const total = sum(data.today.grid, data.today.solar)
          return total !== null ? `${kwh(total)} aujourd'hui` : '—'
        })()}
      />
    </div>
  )
}

function Tile({ label, title, icon, tone, value, detail }: { label: string; title: string; icon: ReactNode; tone: string; value: string; detail: string }) {
  return (
    <StatTile label={label} title={title} icon={icon} tone={tone}>
      <p className="truncate text-lg leading-6 font-bold text-zinc-900 dark:text-slate-50">{value}</p>
      <p className="truncate text-[0.7rem] text-zinc-500 dark:text-slate-400">{detail}</p>
    </StatTile>
  )
}

function Chart({ data, range, onRange }: { data: EnergyData; range: Range; onRange: (r: Range) => void }) {
  const hasSolar = data.today.solar !== null
  const pair = range === 'day' ? data.day : data.month
  const format = range === 'day' ? (v: number) => formatPower(v) : kwh
  const series: Series[] = [
    { label: 'Réseau', values: pair.grid, className: GRID, format },
    ...(hasSolar ? [{ label: 'Solaire', values: pair.solar, className: SOLAR, format }] : []),
  ]

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-1.5">
      <div className="flex items-center gap-3 text-xs text-zinc-500 dark:text-slate-400">
        {series.map((s) => (
          <span key={s.label} className="flex items-center gap-1.5">
            <span className={`size-2 rounded-sm bg-current ${s.className}`} aria-hidden="true" />
            {s.label}
          </span>
        ))}
        <span className="flex-1" />
        <div className="flex rounded-md bg-zinc-100 p-0.5 dark:bg-slate-800">
          {(['day', 'month'] as const).map((r) => (
            <button
              key={r}
              type="button"
              aria-pressed={range === r}
              onClick={() => onRange(r)}
              className={`rounded px-2 py-0.5 focus-visible:outline-2 focus-visible:outline-indigo-500 ${range === r ? 'bg-white font-medium text-zinc-900 shadow-sm dark:bg-slate-700 dark:text-slate-50' : 'hover:text-zinc-800 dark:hover:text-slate-200'}`}
            >
              {r === 'day' ? '24 h' : '30 j'}
            </button>
          ))}
        </div>
      </div>
      <div className="relative min-h-6 flex-1">
        {range === 'day' ? (
          <TimeSeries label="Puissance moyenne, 24 dernières heures" at={pair.at} series={series} clipClassName="rounded-b-lg" />
        ) : (
          <DailyBars label="Énergie par jour, 30 derniers jours" at={pair.at} series={series} formatTotal={kwh} />
        )}
      </div>
    </div>
  )
}
