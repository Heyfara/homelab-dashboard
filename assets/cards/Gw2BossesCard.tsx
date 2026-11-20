import { useEffect, useState } from 'react'
import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { formatRelative } from '../ui/format'
import { Gw2Logo } from '../ui/Gw2Logo'
import { approach, bossesView, countdown, type BossInfo, type BossSlot } from './gw2Bosses'

/** Props de Gw2BossesCard::describe() côté PHP. */
export type Gw2BossesProps = { title: string; link: string; count: number }

/** Données de Gw2BossesCard::fetch() côté PHP : les passages des prochaines 24 h. */
export type Gw2BossesData = {
  /** Fiche de chaque boss, dans l'ordre de l'horaire */
  bosses: Record<string, BossInfo>
  slots: BossSlot[]
  /** Boss faits aujourd'hui ; null si l'API n'a pas répondu ou sans clé */
  done: string[] | null
  resetAt: string
  /** Pourquoi les boss faits sont inconnus */
  doneError: string | null
  /** Icône officielle du point de passage (render.guildwars2.com) */
  waypointIcon: string | null
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
        <Gw2Logo />
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
        {data?.done && <DayGauge bosses={data.bosses} done={data.done} />}
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
          <div role="group" aria-label="Prochain boss" className="flex min-w-0 items-center gap-3 rounded-xl bg-gradient-to-br from-amber-50 to-orange-100 p-2 dark:from-slate-800 dark:to-slate-800/40">
            {next && data.bosses[next.id] ? <NextBoss slot={next} boss={data.bosses[next.id]!} now={now} waypointIcon={data.waypointIcon} /> : <p className="px-1 text-sm text-emerald-700 dark:text-emerald-400">Tous les boss sont faits.</p>}
          </div>

          <ul aria-label="Passages suivants" className="flex min-h-0 min-w-0 flex-col justify-center gap-1 overflow-hidden">
            {list.map((s) => {
              const boss = data.bosses[s.id]
              const name = boss?.name ?? s.id

              return (
                <li key={`${s.id}-${s.startsAt}`} aria-label={s.done ? `${name}, fait` : name} title={boss ? `${name} · ${boss.zone}` : name} className={`flex shrink-0 items-center gap-2 text-xs ${s.done ? 'opacity-50' : ''}`}>
                  <span className="w-9 shrink-0 text-zinc-500 tabular-nums dark:text-slate-400">{hour(s.startsAt)}</span>
                  <Portrait boss={boss} name={name} className="size-4 rounded text-[0.55rem]" />
                  <span className={`min-w-0 flex-1 truncate text-zinc-700 dark:text-slate-200 ${s.done ? 'line-through' : ''}`}>{name}</span>
                  {s.done && <span className="shrink-0 text-emerald-600 dark:text-emerald-400">✓</span>}
                </li>
              )
            })}
          </ul>
        </div>
      )}
    </div>
  )
}

function NextBoss({ slot, boss, now, waypointIcon }: { slot: BossSlot; boss: BossInfo; now: Date; waypointIcon: string | null }) {
  const started = new Date(slot.startsAt).getTime() <= now.getTime()

  return (
    <>
      <Portrait boss={boss} name={boss.name} className="size-16 rounded-lg text-2xl" />
      <div className="flex min-w-0 flex-1 flex-col">
        <p title={boss.name} className="truncate text-sm font-semibold text-zinc-900 dark:text-slate-50">
          {boss.name}
        </p>
        <p title={boss.zone} className="truncate text-[0.7rem] text-zinc-600 dark:text-slate-400">
          {boss.zone}
        </p>
        <p className="mt-0.5 flex items-center gap-1.5 whitespace-nowrap text-xs">
          <span className={`truncate font-semibold tabular-nums ${started ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'}`}>{countdown(slot.startsAt, now)}</span>
          {!started && <span className="text-zinc-500 tabular-nums dark:text-slate-400">· {hour(slot.startsAt)}</span>}
          <span className="flex-1" />
          <CopyWaypoint code={boss.waypoint} icon={waypointIcon} />
        </p>
        <ApproachBar startsAt={slot.startsAt} now={now} />
      </div>
    </>
  )
}

const BAR_COLOR = { waiting: 'bg-amber-400', soon: 'bg-orange-500', active: 'bg-emerald-500' } as const

/** Fine jauge sous le compte à rebours : se remplit avant le boss, se vide pendant le combat. */
function ApproachBar({ startsAt, now }: { startsAt: string; now: Date }) {
  const { phase, ratio } = approach(startsAt, now)
  const percent = Math.round(ratio * 100)

  return (
    <div
      role="progressbar"
      aria-label={phase === 'active' ? 'Combat en cours' : 'Approche du boss'}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={percent}
      className="mt-1 h-1 overflow-hidden rounded-full bg-zinc-900/10 dark:bg-slate-700"
    >
      <div className={`h-full rounded-full transition-[width] duration-1000 ease-linear ${BAR_COLOR[phase]}`} style={{ width: `${percent}%` }} />
    </div>
  )
}

/** Portrait du wiki ; sans portrait, l'initiale du boss. */
function Portrait({ boss, name, className }: { boss: BossInfo | undefined; name: string; className: string }) {
  if (boss?.image) {
    return <img loading="lazy" src={boss.image} alt="" className={`shrink-0 bg-white/60 object-cover object-top dark:bg-slate-700/60 ${className}`} />
  }

  return (
    <span aria-hidden="true" className={`flex shrink-0 items-center justify-center bg-amber-200 font-bold text-amber-800 dark:bg-slate-700 dark:text-amber-300 ${className}`}>
      {name.charAt(0)}
    </span>
  )
}

/** Copie le code du point de passage, à coller dans le chat du jeu. */
function CopyWaypoint({ code, icon }: { code: string; icon: string | null }) {
  const [copied, setCopied] = useState(false)
  useEffect(() => {
    if (!copied) return
    const timer = setTimeout(() => setCopied(false), 1500)

    return () => clearTimeout(timer)
  }, [copied])

  const copy = () => {
    navigator.clipboard?.writeText(code).then(
      () => setCopied(true),
      () => setCopied(false),
    )
  }

  return (
    <button
      type="button"
      onClick={copy}
      aria-label={`Copier le point de passage ${code}`}
      title={`Copier le point de passage ${code}, à coller dans le chat du jeu`}
      className="flex h-8 min-w-8 shrink-0 items-center justify-center overflow-hidden rounded-md bg-white/70 text-[0.7rem] font-semibold text-zinc-700 ring-1 ring-amber-300 hover:bg-white dark:bg-slate-700 dark:text-slate-100 dark:ring-slate-600 dark:hover:bg-slate-600"
    >
      {copied ? <span className="px-1">✓ Copié</span> : icon ? <img src={icon} alt="" className="size-8" /> : <span className="px-1">PP</span>}
    </button>
  )
}

/** Une pastille par boss du jour, pleine quand il est fait. */
function DayGauge({ bosses, done }: { bosses: Record<string, BossInfo>; done: string[] }) {
  const ids = Object.keys(bosses)
  const count = ids.filter((id) => done.includes(id)).length

  return (
    <ul aria-label={`${count} boss sur ${ids.length} faits aujourd’hui`} title={`${count}/${ids.length} boss faits aujourd’hui`} className="flex shrink-0 items-center gap-0.5">
      {ids.map((id) => {
        const isDone = done.includes(id)

        return <li key={id} title={`${bosses[id]!.name} : ${isDone ? 'fait' : 'à faire'}`} className={`size-2 rounded-full ${isDone ? 'bg-emerald-500' : 'bg-zinc-200 dark:bg-slate-700'}`} />
      })}
    </ul>
  )
}
