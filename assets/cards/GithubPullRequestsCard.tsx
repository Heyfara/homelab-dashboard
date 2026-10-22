import type { CSSProperties } from 'react'
import type { CardResult, CardView } from '../http/types'
import { formatRelative } from '../ui/format'
import { CheckIcon, ClockIcon, PackageIcon, PullRequestIcon } from '../ui/icons'
import { ServiceIcon } from '../ui/ServiceIcon'
import { labelTone, parsePrTitle, type LabelTone } from './githubTitle'
import { ExternalLink } from '../ui/ExternalLink'

/** Props de GithubPullRequestsCard::describe() côté PHP. */
export type GithubPrProps = { title: string; repos: string[]; url: string }

export type GithubPr = {
  repo: string
  number: number
  title: string
  url: string
  author: string
  draft: boolean
  labels: { name: string; color: string }[]
  createdAt: string
  updatedAt: string
}

/** Données de GithubPullRequestsCard::fetch() côté PHP. */
export type GithubPrData = { total: number; items: GithubPr[] }

/** Au-delà, une PR qui attend est signalée (âge en ambre). */
const STALE_DAYS = 7

const TONES: Record<'danger' | 'warning' | 'success', string> = {
  danger: 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-400/15 dark:text-rose-300 dark:ring-rose-400/30',
  warning: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-400/15 dark:text-amber-300 dark:ring-amber-400/30',
  success: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-400/15 dark:text-emerald-300 dark:ring-emerald-400/30',
}

// Couleurs stables pour les étiquettes grises sans sens connu (classes écrites en entier).
const PALETTE = [
  'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-400/15 dark:text-indigo-300 dark:ring-indigo-400/30',
  'bg-teal-50 text-teal-700 ring-teal-200 dark:bg-teal-400/15 dark:text-teal-300 dark:ring-teal-400/30',
  'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-200 dark:bg-fuchsia-400/15 dark:text-fuchsia-300 dark:ring-fuchsia-400/30',
  'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-400/15 dark:text-orange-300 dark:ring-orange-400/30',
  'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-400/15 dark:text-sky-300 dark:ring-sky-400/30',
  'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-400/15 dark:text-violet-300 dark:ring-violet-400/30',
]

export function GithubPullRequestsCard({ card, result, now = new Date() }: { card: CardView<GithubPrProps>; result: CardResult<GithubPrData> | null; now?: Date }) {
  const { title, url, repos } = card.props
  const data = result?.status === 'ok' ? result.data : null

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="grid size-7 shrink-0 place-items-center rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400">
          <PullRequestIcon className="size-4" />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        {data && (
          <span
            aria-label={`${data.total} pull requests`}
            className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-bold tabular-nums ${data.total > 0 ? 'bg-violet-600 text-white dark:bg-violet-500' : 'bg-zinc-100 text-zinc-600 dark:bg-slate-800 dark:text-slate-300'}`}
          >
            {data.total}
          </span>
        )}
        <span className="flex-1" />
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Tout voir →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="flex min-h-0 flex-1 flex-col gap-1.5">
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-11 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
          ))}
        </div>
      ) : data.items.length === 0 ? (
        <p className="flex min-h-0 flex-1 items-center justify-center gap-2 text-sm text-zinc-500 dark:text-slate-400">
          <span className="grid size-6 place-items-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
            <CheckIcon className="size-3.5" />
          </span>
          Aucune pull request en attente
        </p>
      ) : (
        // Colonne qui passe à la ligne : une PR qui ne tient pas entière part
        // dans une 2e colonne, hors de la carte.
        <ul className="flex min-h-0 flex-1 flex-col flex-wrap content-start gap-x-8 gap-y-1.5 overflow-hidden">
          {data.items.map((pr) => (
            <PullRequestRow key={pr.url} pr={pr} showRepo={repos.length > 1} now={now} />
          ))}
        </ul>
      )}
    </div>
  )
}

function PullRequestRow({ pr, showRepo, now }: { pr: GithubPr; showRepo: boolean; now: Date }) {
  const parsed = parsePrTitle(pr.title)
  const ageDays = (now.getTime() - new Date(pr.createdAt).getTime()) / 86_400_000
  const stale = ageDays >= STALE_DAYS

  return (
    <li className="w-full">
      <ExternalLink
        href={pr.url}
        aria-label={pr.title}
        title={pr.title}
        className="flex items-center gap-2.5 rounded-lg bg-zinc-50 px-2.5 py-1.5 ring-1 ring-transparent hover:bg-zinc-100 hover:ring-zinc-200 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:bg-slate-800/50 dark:hover:bg-slate-800 dark:hover:ring-slate-700"
      >
        {parsed.icon ? (
          <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
            <ServiceIcon icon={parsed.icon} name={parsed.name.split('/').at(-1) ?? parsed.name} />
          </span>
        ) : (
          <span className="grid size-7 shrink-0 place-items-center rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400">
            <PackageIcon className="size-4" />
          </span>
        )}

        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
          <span className="flex min-w-0 items-center gap-1.5">
            <span className="truncate text-sm font-semibold text-zinc-900 dark:text-slate-50">{parsed.name}</span>
            {parsed.version && <span className="shrink-0 rounded-md bg-violet-500/10 px-1.5 font-mono text-[0.7rem] font-medium text-violet-700 dark:text-violet-300">→ {parsed.version}</span>}
            {pr.draft && <span className="shrink-0 rounded-full bg-zinc-200 px-1.5 text-[0.65rem] text-zinc-600 dark:bg-slate-700 dark:text-slate-300">Brouillon</span>}
          </span>
          <span className="flex min-w-0 items-center gap-1.5 overflow-hidden">
            {showRepo && <span className="shrink-0 text-[0.7rem] font-medium text-zinc-500 dark:text-slate-400">{pr.repo.split('/')[1] ?? pr.repo}</span>}
            {pr.labels.map((label) => (
              <Label key={label.name} name={label.name} tone={labelTone(label.name, label.color)} />
            ))}
          </span>
        </span>

        <span className="flex shrink-0 flex-col items-end gap-0.5 text-[0.7rem] tabular-nums">
          <span className="font-medium text-zinc-500 dark:text-slate-400">#{pr.number}</span>
          <span
            title={`Ouverte le ${new Date(pr.createdAt).toLocaleDateString('fr-FR')}`}
            className={`flex items-center gap-0.5 ${stale ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-zinc-500 dark:text-slate-400'}`}
          >
            <ClockIcon className="size-3" />
            {formatRelative(pr.createdAt, now, 'short')}
          </span>
        </span>
      </ExternalLink>
    </li>
  )
}

function Label({ name, tone }: { name: string; tone: LabelTone }) {
  const base = 'shrink-0 rounded-full px-2 text-[0.65rem] leading-4 font-medium ring-1'

  if (tone.kind === 'color') {
    // Vraie couleur GitHub : fond teinté de la couleur, texte dans la couleur du texte.
    const style = { backgroundColor: `${tone.color}26`, '--tw-ring-color': `${tone.color}80` } as CSSProperties
    return (
      <span className={`${base} text-zinc-800 dark:text-slate-100`} style={style}>
        {name}
      </span>
    )
  }

  return <span className={`${base} ${tone.kind === 'tone' ? TONES[tone.tone] : PALETTE[tone.index]}`}>{name}</span>
}
