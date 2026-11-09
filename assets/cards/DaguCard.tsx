import type { CardComponent } from './registry'
import { formatDuration, formatRelative } from '../ui/format'
import { CheckIcon, ClockIcon } from '../ui/icons'
import { ExternalLink } from '../ui/ExternalLink'
import { StatusPill, type Tone } from '../ui/StatusPill'

/** ok, partial (une étape en échec), failed, running, queued ou unknown */
type RunState = string

export type DaguRun = { state: RunState; startedAt: string; manual: boolean }

export type DaguTask = {
  name: string
  title: string
  description: string | null
  /** Planning en clair, null si la tâche se lance à la main */
  schedule: string | null
  nextRun: string | null
  suspended: boolean
  lastRun: (DaguRun & { finishedAt: string | null }) | null
  /** Du plus ancien au plus récent */
  history: DaguRun[]
  /** Page de la tâche dans Dagu (journaux, bouton de relance) */
  url: string
}

/** Données de DaguCard::fetch() côté PHP. */
export type DaguData = { tasks: DaguTask[] }

const STATES: Record<string, { tone: Tone; label: string }> = {
  ok: { tone: 'ok', label: 'réussie' },
  partial: { tone: 'warn', label: 'en partie' },
  failed: { tone: 'down', label: 'échec' },
  running: { tone: 'unknown', label: 'en cours' },
  queued: { tone: 'unknown', label: 'en attente' },
}
const DOTS: Record<string, string> = { ok: 'bg-emerald-500', partial: 'bg-amber-500', failed: 'bg-rose-500', running: 'bg-sky-500' }

/** État affiché d'une tâche : celui de son dernier passage. */
export function taskStatus(task: DaguTask): { tone: Tone; label: string } {
  if (task.suspended) return { tone: 'unknown', label: 'suspendue' }
  if (task.lastRun === null) return { tone: 'unknown', label: 'jamais lancée' }

  return STATES[task.lastRun.state] ?? { tone: 'unknown', label: task.lastRun.state }
}

function runLabel(task: DaguTask, run: DaguRun): string {
  const when = new Date(run.startedAt).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })

  return `${task.title}, passage du ${when}${run.manual ? ' (à la main)' : ''} : ${STATES[run.state]?.label ?? run.state}`
}

export const DaguCard: CardComponent<{ title: string; url: string }, DaguData> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null
  const failing = data?.tasks.filter((t) => t.lastRun?.state === 'failed').length ?? 0

  return (
    <div className="flex h-full flex-col gap-2.5">
      <header className="flex items-center gap-2">
        <ClockIcon className="size-5 shrink-0 text-zinc-500 dark:text-slate-400" />
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">
          <ExternalLink href={card.props.url} className="hover:underline">
            {card.title ?? card.props.title}
          </ExternalLink>
        </h3>
        <span className="flex-1" />
        {failing > 0 && (
          <span role="alert" className="shrink-0 text-xs font-medium text-rose-700 dark:text-rose-300">
            {failing} en échec
          </span>
        )}
      </header>

      {data === null ? (
        <div aria-busy="true" className="flex flex-1 flex-col gap-1.5">
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-10 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
          ))}
        </div>
      ) : data.tasks.length === 0 ? (
        <p className="flex flex-1 items-center justify-center gap-2 text-sm text-zinc-500 dark:text-slate-400">
          <CheckIcon className="size-4" />
          Aucune tâche à afficher
        </p>
      ) : (
        <ul className="flex min-h-0 flex-1 flex-col gap-1.5 overflow-hidden">
          {data.tasks.map((task) => (
            <li key={task.name}>
              <TaskRow task={task} />
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function TaskRow({ task }: { task: DaguTask }) {
  const status = taskStatus(task)
  const now = new Date()
  const last = task.lastRun
  const lastText =
    last === null
      ? null
      : last.state === 'running'
        ? `depuis ${formatDuration((now.getTime() - new Date(last.startedAt).getTime()) / 1000)}`
        : `${formatRelative(last.startedAt, now, 'short')}${last.manual ? ' (à la main)' : ''}`
  const plan = [task.schedule ?? 'à la main', task.nextRun && !task.suspended ? `prochain ${formatRelative(task.nextRun, now, 'short')}` : null].filter(Boolean).join(' · ')

  return (
    <ExternalLink
      href={task.url}
      title={[task.description, 'Ouvrir dans Dagu (journaux, relance)'].filter(Boolean).join('\n')}
      className="flex flex-col gap-1 rounded-lg bg-zinc-50 px-2.5 py-1.5 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:bg-slate-800/50 dark:hover:bg-slate-800"
    >
      <span className="flex items-center gap-2 text-sm">
        <span className="min-w-0 flex-1 truncate font-medium text-zinc-900 dark:text-slate-50">{task.title}</span>
        {lastText && <span className="shrink-0 text-[0.7rem] text-zinc-500 tabular-nums dark:text-slate-400">{lastText}</span>}
        <StatusPill tone={status.tone} label={status.label} />
      </span>
      <span className="flex items-center gap-2">
        <span className="min-w-0 flex-1 truncate text-[0.7rem] text-zinc-500 dark:text-slate-400">{plan}</span>
        <span className="flex w-24 shrink-0 items-center gap-0.5" aria-label="Historique des passages">
          {task.history.map((run) => (
            <span
              key={run.startedAt}
              role="img"
              aria-label={runLabel(task, run)}
              title={runLabel(task, run)}
              className={`h-1.5 flex-1 rounded-full ${DOTS[run.state] ?? 'bg-zinc-400'} ${run.manual ? 'opacity-50' : ''}`}
            />
          ))}
        </span>
      </span>
    </ExternalLink>
  )
}
