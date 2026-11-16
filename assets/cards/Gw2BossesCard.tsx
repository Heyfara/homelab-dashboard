import { useEffect, useState } from 'react'
import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { formatRelative } from '../ui/format'
import { bossesView, countdown, type BossSlot } from './gw2Bosses'

/** Props de Gw2BossesCard::describe() côté PHP. */
export type Gw2BossesProps = { title: string; link: string; count: number }

/** Données de Gw2BossesCard::fetch() côté PHP : les passages des prochaines 24 h. */
export type Gw2BossesData = {
  slots: BossSlot[]
  /** Boss faits aujourd'hui ; null si l'API n'a pas répondu ou sans clé */
  done: number | null
  total: number
  resetAt: string
  /** Pourquoi les boss faits sont inconnus */
  doneError: string | null
}

const time = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' })
const hour = (iso: string) => time.format(new Date(iso))

/** L'heure, rafraîchie chaque seconde : le décompte avance sans redemander les données. */
function useNow(): Date {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), 1000)

    return () => clearInterval(timer)
  }, [])

  return now
}

export const Gw2BossesCard: CardComponent<Gw2BossesProps, Gw2BossesData> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null
  const now = useNow()
  const { next, list } = data ? bossesView(data.slots, card.props.count, now) : { next: null, list: [] }

  return (
    <div className="flex h-full flex-col gap-2">
      <header className="flex items-center gap-2">
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">
          <ExternalLink href={card.props.link} title="Horaires des événements sur le wiki" className="hover:underline">
            {card.title ?? card.props.title}
          </ExternalLink>
        </h3>
        <span className="flex-1" />
        {data?.doneError && (
          <span title={data.doneError} className="shrink-0 cursor-help text-xs text-amber-600 dark:text-amber-400">
            ⚠
          </span>
        )}
        {data && data.done !== null && (
          <span title="Boss faits aujourd’hui" className="shrink-0 text-xs font-semibold text-zinc-700 tabular-nums dark:text-slate-200">
            {data.done}/{data.total}
          </span>
        )}
        {data && (
          <span title="Réinitialisation quotidienne (0 h UTC)" className="shrink-0 text-[0.7rem] text-zinc-500 dark:text-slate-400">
            ↻ {formatRelative(data.resetAt, now, 'short')}
          </span>
        )}
      </header>

      {data === null ? (
        <div aria-busy="true" className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      ) : (
        <div className="grid min-h-0 flex-1 grid-cols-2 gap-3">
          <div role="group" aria-label="Prochain boss" className="flex min-w-0 flex-col justify-center rounded-lg bg-amber-50 px-3 py-1.5 dark:bg-slate-800">
            {next ? (
              <>
                <p title={next.name} className="truncate text-sm font-semibold text-zinc-900 dark:text-slate-50">
                  {next.name}
                </p>
                <p className="flex items-baseline gap-1.5 text-xs">
                  <span className="font-semibold text-amber-700 tabular-nums dark:text-amber-400">{countdown(next.startsAt, now)}</span>
                  <span className="text-zinc-500 tabular-nums dark:text-slate-400">· {hour(next.startsAt)}</span>
                </p>
              </>
            ) : (
              <p className="text-sm text-emerald-700 dark:text-emerald-400">Tous les boss sont faits.</p>
            )}
          </div>

          <ul aria-label="Passages suivants" className="flex min-h-0 min-w-0 flex-col justify-center gap-0.5 overflow-hidden">
            {list.map((s) => (
              <li
                key={`${s.id}-${s.startsAt}`}
                aria-label={s.done ? `${s.name}, fait` : s.name}
                title={s.name}
                className={`flex shrink-0 items-center gap-2 text-xs ${s.done ? 'opacity-50' : ''}`}
              >
                <span className="shrink-0 text-zinc-500 tabular-nums dark:text-slate-400">{hour(s.startsAt)}</span>
                <span className={`min-w-0 flex-1 truncate text-zinc-700 dark:text-slate-200 ${s.done ? 'line-through' : ''}`}>{s.name}</span>
                {s.done && <span className="shrink-0 text-emerald-600 dark:text-emerald-400">✓</span>}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
