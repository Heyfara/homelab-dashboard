import type { CardResult, CardView } from '../http/types'
import { ExternalLink } from '../ui/ExternalLink'
import { formatBytes, formatDay, formatNumber, formatRelative } from '../ui/format'
import { ClockIcon, CoinIcon, PackageIcon } from '../ui/icons'
import { ServiceIcon } from '../ui/ServiceIcon'
import { StatTile } from '../ui/StatTile'
import { TimeSeries } from '../ui/TimeSeries'

/** Props de BackblazeCard::describe() côté PHP. */
export type BackblazeProps = { title: string; url: string; staleHours: number }

export type BackblazeBucket = { name: string; bytes: number; files: number; lastUpload: string | null }

/** Tarif en vigueur à partir de `from` (AAAA-MM-JJ). */
export type BackblazePrice = { from: string; pricePerTb: number }

/** Relevé quotidien : taille des buckets de la carte et coût mensuel au tarif du jour (TVA comprise). */
export type BackblazeDay = { date: string; bytes: number; monthly: number; pricePerTb: number }

/** Données de BackblazeCard::fetch() côté PHP. */
export type BackblazeData = {
  buckets: BackblazeBucket[]
  total: { bytes: number; files: number }
  /** monthly : ce qui est payé, TVA comprise si `vat` > 0 ; prices : tarifs datés, du plus ancien au plus récent */
  cost: { monthly: number; monthlyExclVat: number; vat: number; pricePerTb: number; freeGb: number; currency: string; prices: BackblazePrice[] }
  history: BackblazeDay[]
}

const money = (value: number, currency: string) =>
  `${new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} ${currency}`

export function BackblazeCard({ card, result, now = new Date() }: { card: CardView<BackblazeProps>; result: CardResult<BackblazeData> | null; now?: Date }) {
  const { title, url, staleHours } = card.props
  const data = result?.status === 'ok' ? result.data : null

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon="sh-backblaze" name="Backblaze" />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        <span className="flex-1" />
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir B2 →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="flex min-h-0 flex-1 flex-col gap-3">
          <div className="grid h-20 grid-cols-2 gap-3">
            <div className="animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
            <div className="animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
          </div>
          <div className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
        </div>
      ) : (
        <>
          {/* Trois lignes par tuile au plus : la carte tient sur une rangée (2 × 1) avec un seul bucket. */}
          <div className="grid grid-cols-2 gap-3">
            <StatTile
              label="Coût estimé"
              title={`Estimation : B2 ne publie pas sa facturation par API. Taille stockée × ${money(data.cost.pricePerTb, data.cost.currency)}/To, ${formatNumber(data.cost.freeGb)} Go offerts${data.cost.vat > 0 ? ` ; ${money(data.cost.monthlyExclVat, data.cost.currency)} HT + TVA ${formatNumber(data.cost.vat)} %` : ''}.${
                data.cost.prices.length > 1 ? `\nTarifs : ${data.cost.prices.map((p) => `${money(p.pricePerTb, data.cost.currency)}/To dès le ${formatDay(p.from)}`).join(', ')}.` : ''
              }`}
              icon={<CoinIcon className="size-4" />}
              tone="bg-rose-500/8 [&_[data-chip]]:bg-rose-500/15 [&_[data-chip]]:text-rose-600 dark:[&_[data-chip]]:text-rose-400"
            >
              <p className="flex items-baseline gap-1">
                <span className="text-lg leading-6 font-bold text-zinc-900 dark:text-slate-50">≈ {money(data.cost.monthly, data.cost.currency)}</span>
                <span className="text-xs text-zinc-500 dark:text-slate-400">/mois{data.cost.vat > 0 && ' TTC'}</span>
              </p>
              <p className="truncate text-[0.7rem] text-zinc-500 dark:text-slate-400">
                ≈ {money(data.cost.monthly * 12, data.cost.currency)}/an · {money(data.cost.pricePerTb, data.cost.currency)}/To
              </p>
            </StatTile>
            <StatTile
              label="Stocké"
              title={data.buckets.map((b) => b.name).join(', ')}
              icon={<PackageIcon className="size-4" />}
              tone="bg-sky-500/8 [&_[data-chip]]:bg-sky-500/15 [&_[data-chip]]:text-sky-600 dark:[&_[data-chip]]:text-sky-400"
            >
              <p className="text-lg leading-6 font-bold text-zinc-900 dark:text-slate-50">{formatBytes(data.total.bytes)}</p>
              <p className="flex min-w-0 items-center gap-1.5 text-[0.7rem] text-zinc-500 dark:text-slate-400">
                <span className="truncate">{formatNumber(data.total.files)} fichiers</span>
                {data.buckets.length === 1 && (
                  <>
                    ·
                    <LastUpload iso={data.buckets[0]!.lastUpload} staleHours={staleHours} now={now} />
                  </>
                )}
              </p>
            </StatTile>
          </div>

          {data.buckets.length > 1 && (
            <ul className={`flex min-h-0 flex-col flex-wrap content-start gap-x-8 gap-y-1.5 overflow-hidden ${card.rows > 1 ? 'shrink-0' : 'flex-1'}`}>
              {data.buckets.map((bucket) => (
                <BucketRow key={bucket.name} bucket={bucket} staleHours={staleHours} now={now} />
              ))}
            </ul>
          )}

          {/* Une rangée suffit aux tuiles : le graphique demande la deuxième. */}
          {card.rows > 1 && <CostHistory history={data.history} currency={data.cost.currency} vat={data.cost.vat} />}
        </>
      )}
    </div>
  )
}

/** Coût mensuel relevé chaque jour, au tarif du jour ; le tarif au survol. */
function CostHistory({ history, currency, vat }: { history: BackblazeDay[]; currency: string; vat: number }) {
  return (
    <div className="relative min-h-6 flex-1">
      <TimeSeries
        label={`Coût mensuel${vat > 0 ? ' TTC' : ''} depuis le ${history[0] ? formatDay(history[0].date) : '—'}`}
        at={history.map((d) => d.date)}
        formatAt={formatDay}
        notes={history.map((d) => `${money(d.pricePerTb, currency)}/To`)}
        series={[{ label: '/mois', values: history.map((d) => d.monthly), className: 'text-rose-500 dark:text-rose-400', format: (v) => money(v, currency) }]}
        clipClassName="rounded-b-lg"
      />
    </div>
  )
}

function BucketRow({ bucket, staleHours, now }: { bucket: BackblazeBucket; staleHours: number; now: Date }) {
  return (
    <li className="flex w-full items-center gap-2.5 rounded-lg bg-zinc-50 px-2.5 py-1.5 text-sm tabular-nums dark:bg-slate-800/50">
      <span className="min-w-0 flex-1 truncate font-mono text-[0.8rem] text-zinc-800 dark:text-slate-100" title={bucket.name}>
        {bucket.name}
      </span>
      <span className="shrink-0 font-semibold text-zinc-900 dark:text-slate-50">{formatBytes(bucket.bytes)}</span>
      <span className="w-24 shrink-0 text-right text-xs text-zinc-500 dark:text-slate-400">{formatNumber(bucket.files)} fichiers</span>
      <span className="flex w-24 shrink-0 justify-end text-xs">
        <LastUpload iso={bucket.lastUpload} staleHours={staleHours} now={now} />
      </span>
    </li>
  )
}

/** Dernier envoi, en ambre au-delà de `staleHours` (une sauvegarde de nuit manquée). */
function LastUpload({ iso, staleHours, now }: { iso: string | null; staleHours: number; now: Date }) {
  const stale = iso === null || (now.getTime() - new Date(iso).getTime()) / 3_600_000 > staleHours

  return (
    <span
      data-last-upload
      title={iso ? `Dernier envoi le ${new Date(iso).toLocaleString('fr-FR')}` : 'Aucun envoi'}
      className={`flex shrink-0 items-center gap-1 ${stale ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-zinc-500 dark:text-slate-400'}`}
    >
      <ClockIcon className="size-3" />
      {iso ? formatRelative(iso, now, 'short') : 'jamais'}
    </span>
  )
}
