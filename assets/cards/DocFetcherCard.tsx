import type { CardComponent } from './registry'
import { formatRelative } from '../ui/format'
import { AlertIcon, CheckIcon } from '../ui/icons'
import { ExternalLink } from '../ui/ExternalLink'
import { ServiceIcon } from '../ui/ServiceIcon'
import { StatusPill, type Tone } from '../ui/StatusPill'

/** ok, failed, ou action : un site attend une intervention (code de double authentification). */
type RunState = string

export type DocFetcherSite = {
  name: string
  label: string
  state: RunState
  /** Passage qui a donné cet état (un passage --site ne touche que son site) */
  checkedAt: string
  /** Documents listés sur le site ; null si la connexion a échoué */
  found: number | null
  missing: number | null
  errors: string[]
}

export type DocFetcherRun = { startedAt: string; state: RunState; manual: boolean; imported: number }

export type DocFetcherDocument = { id: number; title: string; created: string | null; site: string; importedAt: string; url: string | null }

/** Données de DocFetcherCard::fetch() côté PHP. */
export type DocFetcherData = {
  schedule: string | null
  lastRun: { startedAt: string; finishedAt: string | null; state: RunState; manual: boolean } | null
  /** Le cron n'est pas repassé depuis stale_days */
  stale: boolean
  sites: DocFetcherSite[]
  /** Du plus ancien au plus récent */
  history: DocFetcherRun[]
  importedTotal: number
  documents: DocFetcherDocument[]
}

const TONES: Record<string, Tone> = { ok: 'ok', action: 'warn', failed: 'down' }
const DOTS: Record<string, string> = { ok: 'bg-emerald-500', action: 'bg-amber-500', failed: 'bg-rose-500' }
const STATES: Record<string, string> = { ok: 'réussi', action: 'code à saisir', failed: 'échec' }

const day = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' })

/** Résumé court d'un site, à côté de son nom. */
export function siteStatus(site: DocFetcherSite): string {
  if (site.state === 'ok') return site.found === null ? 'à jour' : `${site.found} sur le site`

  return STATES[site.state] ?? site.state
}

function runLabel(run: DocFetcherRun): string {
  const docs = run.imported === 0 ? 'aucun document importé' : `${run.imported} ${run.imported > 1 ? 'documents importés' : 'document importé'}`

  return `Passage du ${new Date(run.startedAt).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}${run.manual ? ' (manuel)' : ''} : ${STATES[run.state] ?? run.state}, ${docs}`
}

export const DocFetcherCard: CardComponent<{ title: string }, DocFetcherData> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null
  const blocked = data?.sites.find((s) => s.state !== 'ok' && s.errors.length > 0)

  return (
    <div className="flex h-full flex-col gap-2.5">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon="sh-paperless-ngx" name="Paperless" />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? card.props.title}</h3>
        <span className="flex-1" />
        {data?.lastRun &&
          (data.stale ? (
            <span role="alert" className="shrink-0 text-xs font-medium text-amber-700 dark:text-amber-300" title="Le cron ne passe plus : vérifier la crontab d'helium.">
              {formatRelative(data.lastRun.startedAt, new Date(), 'short')} · passage automatique en retard
            </span>
          ) : (
            <span className="shrink-0 text-xs text-zinc-500 dark:text-slate-400" title={new Date(data.lastRun.startedAt).toLocaleString('fr-FR')}>
              {data.lastRun.manual ? 'lancé à la main ' : ''}
              {formatRelative(data.lastRun.startedAt, new Date(), 'short')}
            </span>
          ))}
      </header>

      {data === null ? (
        <div aria-busy="true" className="flex flex-1 flex-col gap-1.5">
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-8 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
          ))}
        </div>
      ) : (
        <>
          <ul className="flex flex-wrap gap-1.5">
            {data.sites.map((site) => (
              <li key={site.name} aria-label={site.label} title={[`Vérifié ${formatRelative(site.checkedAt)}`, ...site.errors].join('\n')} className="flex items-center gap-1.5 text-sm">
                <span className="font-medium text-zinc-800 dark:text-slate-100">{site.label}</span>
                <StatusPill tone={TONES[site.state] ?? 'unknown'} label={siteStatus(site)} />
              </li>
            ))}
          </ul>

          {blocked && (
            <p role="alert" className="flex items-start gap-1.5 rounded-lg bg-amber-500/10 px-2.5 py-1.5 text-xs text-amber-800 dark:text-amber-200">
              <AlertIcon className="mt-px size-3.5 shrink-0" />
              <span className="line-clamp-2">
                {blocked.label} : {blocked.errors[0]}
              </span>
            </p>
          )}

          <div className="flex items-center gap-1" aria-label="Historique des passages">
            {data.history.map((run) => (
              <span key={run.startedAt} role="img" aria-label={runLabel(run)} title={runLabel(run)} className={`h-2.5 flex-1 rounded-full ${DOTS[run.state] ?? 'bg-zinc-400'} ${run.manual ? 'opacity-50' : ''}`} />
            ))}
          </div>

          {data.documents.length === 0 ? (
            <p className="flex min-h-0 flex-1 items-center justify-center gap-2 text-sm text-zinc-500 dark:text-slate-400">
              <CheckIcon className="size-4" />
              Aucun document importé récemment
            </p>
          ) : (
            <ul className="flex min-h-0 flex-1 flex-col gap-1 overflow-hidden">
              {data.documents.map((doc) => (
                <li key={doc.id}>
                  <DocumentRow doc={doc} />
                </li>
              ))}
            </ul>
          )}

          {data.schedule && <p className="truncate text-[0.7rem] text-zinc-500 dark:text-slate-400">Passage {data.schedule}</p>}
        </>
      )}
    </div>
  )
}

function DocumentRow({ doc }: { doc: DocFetcherDocument }) {
  const content = (
    <>
      <span className="min-w-0 flex-1 truncate font-medium text-zinc-900 dark:text-slate-50">{doc.title}</span>
      <span className="shrink-0 text-[0.7rem] text-zinc-500 dark:text-slate-400">{doc.site}</span>
      <span className="w-14 shrink-0 text-right text-[0.7rem] text-zinc-500 tabular-nums dark:text-slate-400">{doc.created ? day.format(new Date(doc.created)) : '—'}</span>
    </>
  )
  const row = 'flex items-center gap-2 rounded-lg bg-zinc-50 px-2.5 py-1 text-sm dark:bg-slate-800/50'
  const title = `Importé ${formatRelative(doc.importedAt)}`

  return doc.url ? (
    <ExternalLink href={doc.url} title={title} className={`${row} hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-slate-800`}>
      {content}
    </ExternalLink>
  ) : (
    <span title={title} className={row}>
      {content}
    </span>
  )
}
