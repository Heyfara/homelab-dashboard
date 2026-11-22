import type { CardResult, CardView } from '../http/types'
import { CheckIcon } from '../ui/icons'
import { ExternalLink } from '../ui/ExternalLink'
import { ServiceIcon } from '../ui/ServiceIcon'
import { formatAmount, invoiceDue, type DueTone } from './invoiceDue'

/** Props de FacturesCard::describe() côté PHP. */
export type FacturesProps = { title: string; url: string; soonDays: number }

export type Facture = {
  id: number
  title: string
  correspondent: string | null
  /** En unités de la devise (246.5 = 246,50 €), null si le champ est vide ou absent */
  amount: number | null
  currency: string
  /** AAAA-MM-JJ */
  dueDate: string | null
  created: string | null
  url: string
}

/** Données de FacturesCard::fetch() côté PHP. */
export type FacturesData = {
  /** Par échéance croissante, sans échéance à la fin */
  invoices: Facture[]
  /** Somme des montants connus dans `currency` (la devise la plus courante) */
  total: number
  currency: string
  count: number
  /** Factures sans montant connu, hors du total */
  missingAmounts: number
}

const DUE: Record<DueTone, string> = {
  late: 'font-semibold text-rose-600 dark:text-rose-400',
  soon: 'font-medium text-amber-600 dark:text-amber-400',
  later: 'text-zinc-500 dark:text-slate-400',
  unknown: 'text-zinc-400 dark:text-slate-500',
}

const ROW_ACCENT: Record<DueTone, string> = {
  late: 'ring-rose-300/70 dark:ring-rose-400/30',
  soon: 'ring-amber-300/70 dark:ring-amber-400/30',
  later: 'ring-transparent',
  unknown: 'ring-transparent',
}

export function FacturesCard({ card, result, now = new Date() }: { card: CardView<FacturesProps>; result: CardResult<FacturesData> | null; now?: Date }) {
  const { title, url, soonDays } = card.props
  const data = result?.status === 'ok' ? result.data : null
  const late = data?.invoices.filter((invoice) => invoiceDue(invoice.dueDate, now, soonDays).tone === 'late').length ?? 0

  return (
    <div className="flex h-full flex-col gap-2.5">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon="sh-paperless-ngx" name="Paperless" />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        {data && (
          <span
            aria-label={`${data.count} ${data.count > 1 ? 'factures' : 'facture'} en attente`}
            className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-bold tabular-nums ${data.count > 0 ? 'bg-zinc-800 text-white dark:bg-slate-200 dark:text-slate-900' : 'bg-zinc-100 text-zinc-600 dark:bg-slate-800 dark:text-slate-300'}`}
          >
            {data.count}
          </span>
        )}
        {late > 0 && (
          <span
            aria-label={`${late} ${late > 1 ? 'factures' : 'facture'} en retard`}
            className="shrink-0 rounded-full bg-rose-600 px-2 py-0.5 text-xs font-bold whitespace-nowrap text-white tabular-nums dark:bg-rose-500"
          >
            {late} en retard
          </span>
        )}
        <span className="flex-1" />
        <ExternalLink
          href={url}
          aria-label="Ouvrir Paperless"
          className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
        >
          Paperless →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="flex min-h-0 flex-1 flex-col gap-1.5">
          <div className="h-7 w-32 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-10 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
          ))}
        </div>
      ) : data.invoices.length === 0 ? (
        <p className="flex min-h-0 flex-1 items-center justify-center gap-2 text-sm text-zinc-500 dark:text-slate-400">
          <span className="grid size-6 place-items-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
            <CheckIcon className="size-3.5" />
          </span>
          Aucune facture en attente
        </p>
      ) : (
        <>
          <p className="flex items-baseline gap-2">
            <span aria-label="Total à payer" className="text-2xl font-semibold text-zinc-900 tabular-nums dark:text-slate-50">
              {formatAmount(data.total, data.currency)}
              {data.missingAmounts > 0 && (
                <span title={`${data.missingAmounts} sans montant connu`} className="text-base font-medium text-zinc-400 dark:text-slate-500">
                  {' '}+ ?
                </span>
              )}
            </span>
            <span className="text-xs text-zinc-500 dark:text-slate-400">à payer</span>
          </p>

          <ul className="flex min-h-0 flex-1 flex-col gap-1 overflow-hidden">
            {data.invoices.map((invoice) => (
              <InvoiceRow key={invoice.id} invoice={invoice} now={now} soonDays={soonDays} />
            ))}
          </ul>
        </>
      )}
    </div>
  )
}

function InvoiceRow({ invoice, now, soonDays }: { invoice: Facture; now: Date; soonDays: number }) {
  const due = invoiceDue(invoice.dueDate, now, soonDays)
  const dueTitle = invoice.dueDate ? `Échéance le ${new Date(`${invoice.dueDate}T00:00:00`).toLocaleDateString('fr-FR')}` : 'Échéance inconnue'

  return (
    <li className="shrink-0">
      <ExternalLink
        href={invoice.url}
        title={invoice.title}
        className={`flex items-center gap-2.5 rounded-lg bg-zinc-50 px-2.5 py-1 ring-1 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:bg-slate-800/50 dark:hover:bg-slate-800 ${ROW_ACCENT[due.tone]}`}
      >
        <span className="flex min-w-0 flex-1 flex-col">
          <span className="truncate text-sm font-medium text-zinc-900 dark:text-slate-50">{invoice.title}</span>
          {invoice.correspondent && <span className="truncate text-[0.7rem] text-zinc-500 dark:text-slate-400">{invoice.correspondent}</span>}
        </span>
        <span className="flex shrink-0 flex-col items-end text-right tabular-nums">
          <span className={`text-sm font-semibold ${invoice.amount === null ? 'text-zinc-400 dark:text-slate-500' : 'text-zinc-900 dark:text-slate-50'}`}>{formatAmount(invoice.amount, invoice.currency)}</span>
          <span title={dueTitle} className={`text-[0.7rem] ${DUE[due.tone]}`}>
            {due.label}
          </span>
        </span>
      </ExternalLink>
    </li>
  )
}
