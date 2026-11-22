import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { ServiceIcon } from '../ui/ServiceIcon'

/** Props de GazetteCard::describe() côté PHP. */
export type GazetteProps = { title: string; service: string; url: string; icon: string | null }

export type GazetteSentIssue = { number: number; title: string; month: string; sentAt: string; link: string }

/** brouillon, ou teste : envoyé en test, reste l'envoi à la famille */
export type GazetteDraft = { number: number; title: string; month: string; status: string; notes: number; link: string }

/** Données de GazetteCard::fetch() côté PHP. */
export type GazetteData = {
  /** Jours calendaires depuis le dernier envoi ; null si aucun numéro n'est parti */
  daysSince: number | null
  late: boolean
  warnAfterDays: number
  /** Le plus récent d'abord */
  sent: GazetteSentIssue[]
  draft: GazetteDraft | null
}

const monthName = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric', timeZone: 'UTC' })
const day = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', timeZone: 'Europe/Paris' })

/** Titre du numéro, sinon son mois (« septembre 2026 »). */
export function issueLabel(issue: { title: string; month: string }): string {
  return issue.title !== '' ? issue.title : monthName.format(new Date(`${issue.month}-01T00:00:00Z`))
}

export function draftLabel(draft: GazetteDraft): string {
  const state = draft.status === 'teste' ? 'testé, à envoyer' : 'en préparation'
  const notes = draft.notes === 0 ? 'carnet vide' : `${draft.notes} ${draft.notes > 1 ? 'notes' : 'note'} au carnet`

  return `n° ${draft.number} ${state} · ${notes}`
}

const row = 'flex items-center gap-2 rounded-lg px-2.5 py-1 text-sm hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-slate-800'

export const GazetteCard: CardComponent<GazetteProps, GazetteData> = ({ card, result }) => {
  const { title, service, url, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null
  const last = data?.sent[0]

  return (
    <div className="flex h-full flex-col gap-2.5">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon={icon} name={service} />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        <span className="flex-1" />
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="flex min-h-0 flex-1 flex-col gap-2">
          <div className="h-16 animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
          <div className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
        </div>
      ) : (
        <>
          {data.daysSince === null || !last ? (
            <p className="rounded-xl bg-zinc-50 px-3 py-2.5 text-sm text-zinc-600 dark:bg-slate-800/50 dark:text-slate-300">Pas encore envoyée</p>
          ) : (
            <div
              role={data.late ? 'alert' : 'group'}
              aria-label="Depuis la dernière gazette"
              title={data.late ? `Plus de ${data.warnAfterDays} jours sans gazette` : undefined}
              className={`flex items-baseline gap-2.5 rounded-xl px-3 py-2 tabular-nums ${data.late ? 'bg-amber-500/10 text-amber-800 dark:text-amber-200' : 'bg-orange-700/8 text-zinc-900 dark:bg-orange-400/10 dark:text-slate-50'}`}
            >
              <span className="text-3xl font-semibold tracking-tight">J+{data.daysSince}</span>
              <span className="min-w-0 text-xs leading-4 text-zinc-500 dark:text-slate-400">
                depuis le n° {last.number}
                <br />
                envoyé le {day.format(new Date(last.sentAt))}
              </span>
            </div>
          )}

          {data.sent.length > 0 && (
            <ul aria-label="Derniers numéros envoyés" className="-mx-1 flex min-h-0 flex-1 flex-col gap-0.5 overflow-hidden">
              {data.sent.map((issue) => (
                <li key={issue.number}>
                  <ExternalLink href={issue.link} title={`Envoyé le ${new Date(issue.sentAt).toLocaleString('fr-FR')}`} className={row}>
                    <span className="w-8 shrink-0 text-xs text-zinc-500 tabular-nums dark:text-slate-400">n° {issue.number}</span>
                    <span className="min-w-0 flex-1 truncate font-medium text-zinc-900 first-letter:uppercase dark:text-slate-50">{issueLabel(issue)}</span>
                    <span className="shrink-0 text-[0.7rem] text-zinc-500 tabular-nums dark:text-slate-400">{day.format(new Date(issue.sentAt))}</span>
                  </ExternalLink>
                </li>
              ))}
            </ul>
          )}

          {data.sent.length === 0 && <span className="flex-1" />}

          {data.draft && (
            <ExternalLink href={data.draft.link} className={`${row} -mx-1 text-xs text-zinc-600 dark:text-slate-300`}>
              <span className="size-1.5 shrink-0 rounded-full bg-orange-600 dark:bg-orange-400" aria-hidden="true" />
              <span className="min-w-0 truncate">{draftLabel(data.draft)}</span>
            </ExternalLink>
          )}
        </>
      )}
    </div>
  )
}
