import { useRef } from 'react'
import type { CardComponent } from './registry'
import { useOverflowCount } from '../hooks/useOverflowCount'
import { formatNumber } from '../ui/format'
import { ServiceIcon } from '../ui/ServiceIcon'
import type { Tone } from '../ui/StatusPill'

/** Props de ContainersCard::describe() côté PHP. */
export type ContainersProps = { title: string; system: string }

export type ContainerItem = {
  name: string
  status: 'running' | 'restarting' | 'paused' | 'stopped' | string
  health: 'starting' | 'healthy' | 'unhealthy' | null | string
  /** « 7 j » */
  uptime: string | null
  /** % d'un cœur */
  cpu: number | null
  /** Mo */
  memory: number | null
}

/** Données de ContainersCard::fetch() côté PHP. */
export type ContainersData = {
  containers: ContainerItem[]
  counts: { total: number; running: number; problems: number }
}

/** État lisible d'un container. */
export function containerStatus(status: string, health: string | null): { tone: Tone; label: string } {
  if (status === 'stopped') return { tone: 'down', label: 'Arrêté' }
  if (status === 'restarting') return { tone: 'warn', label: 'Redémarre' }
  if (status === 'paused') return { tone: 'warn', label: 'En pause' }
  if (health === 'unhealthy') return { tone: 'down', label: 'En difficulté' }
  if (health === 'starting') return { tone: 'warn', label: 'Démarre' }
  if (health === 'healthy') return { tone: 'ok', label: 'Sain' }
  return { tone: 'ok', label: 'En marche' }
}

const DOT: Record<Tone, string> = {
  ok: 'bg-emerald-500',
  warn: 'bg-amber-500',
  down: 'bg-rose-500',
  unknown: 'bg-zinc-400',
}

const LABEL: Record<Tone, string> = {
  ok: 'text-zinc-500 dark:text-slate-400',
  warn: 'font-medium text-amber-700 dark:text-amber-300',
  down: 'font-medium text-rose-700 dark:text-rose-300',
  unknown: 'text-zinc-500',
}

/** Un container qui tourne utilise toujours de la mémoire : 0 = non mesurée (Raspberry Pi sans cgroup mémoire). */
const hasMemory = (c: ContainerItem) => c.memory !== null && c.memory > 0

/** Mémoire en Mo (Beszel) : « 512 Mo », « 1,5 Go ». */
const formatMegabytes = (mb: number) => (mb >= 1000 ? `${formatNumber(mb / 1024)} Go` : `${formatNumber(Math.round(mb))} Mo`)

export const ContainersCard: CardComponent<ContainersProps, ContainersData> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null
  const list = useRef<HTMLUListElement>(null)
  const hidden = useOverflowCount(list, data?.containers.length)

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon="sh-docker" name="Docker" />
        </span>
        <h3 className="truncate font-semibold text-zinc-900 capitalize dark:text-slate-50">{card.title ?? card.props.title}</h3>
        {data && (
          <>
            <span className="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 tabular-nums dark:bg-slate-800 dark:text-slate-200">
              {data.counts.total} containers
            </span>
            {data.counts.problems > 0 ? (
              <span className="shrink-0 rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-800 tabular-nums dark:bg-rose-400/15 dark:text-rose-300">
                {data.counts.problems} {data.counts.problems > 1 ? 'problèmes' : 'problème'}
              </span>
            ) : (
              <span className="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-300">Tout fonctionne</span>
            )}
            {hidden > 0 && (
              <span title="Agrandir la carte (rows dans le YAML) pour tous les voir" className="ml-auto shrink-0 text-xs text-zinc-500 dark:text-slate-400">
                +{hidden} masqués
              </span>
            )}
          </>
        )}
      </header>

      {data === null ? (
        <div aria-busy="true" className="grid min-h-0 flex-1 grid-cols-2 gap-x-6 gap-y-2">
          {Array.from({ length: 8 }, (_, i) => (
            <div key={i} className="h-5 animate-pulse rounded bg-zinc-100 dark:bg-slate-800" />
          ))}
        </div>
      ) : data.containers.length === 0 ? (
        <p className="grid min-h-0 flex-1 place-items-center text-sm text-zinc-500 dark:text-slate-400">Aucun container</p>
      ) : (
        // Colonnes remplies de haut en bas ; celles qui ne tiennent pas dans la
        // carte sont masquées, jamais une ligne coupée. Problèmes en premier.
        <ul ref={list} className="min-h-0 flex-1 columns-2 gap-x-6 overflow-hidden [column-fill:auto]">
          {data.containers.map((c) => (
            <ContainerRow key={c.name} container={c} />
          ))}
        </ul>
      )}
    </div>
  )
}

function ContainerRow({ container }: { container: ContainerItem }) {
  const status = containerStatus(container.status, container.health)
  const details = [
    status.label,
    container.uptime && `depuis ${container.uptime}`,
    container.cpu !== null && `CPU ${formatNumber(container.cpu)} %`,
    hasMemory(container) && formatMegabytes(container.memory!),
  ]
    .filter(Boolean)
    .join(' · ')

  return (
    <li title={`${container.name} : ${details}`} className="flex break-inside-avoid items-center gap-2 py-0.5 text-sm">
      <span data-dot className={`size-2 shrink-0 rounded-full ${DOT[status.tone]}`} aria-hidden="true" />
      <span data-name className="min-w-0 flex-1 truncate font-mono text-[0.8rem] text-zinc-800 dark:text-slate-100">
        {container.name}
      </span>
      {status.tone === 'ok' ? (
        <span className="shrink-0 text-xs text-zinc-500 tabular-nums dark:text-slate-400">
          <span className="sr-only">{status.label}, </span>
          {container.uptime}
          {hasMemory(container) && <span className="ml-2 inline-block w-14 text-right">{formatMegabytes(container.memory!)}</span>}
        </span>
      ) : (
        <span className={`shrink-0 text-xs ${LABEL[status.tone]}`}>{status.label}</span>
      )}
    </li>
  )
}
