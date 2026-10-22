import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { ServiceIcon } from '../ui/ServiceIcon'

/** Props de MinecraftCard::describe() côté PHP. */
export type MinecraftProps = { title: string; icon: string | null }

/** Données de MinecraftCard::fetch() côté PHP. */
export type MinecraftData = {
  server: { state: string; online: number; max: number; players: string[]; version: string | null; link: string }
  image: { url: string; renderedAt: string | null }
}

const STATES: Record<string, { label: string; pill: string }> = {
  online: { label: 'En ligne', pill: 'bg-emerald-500 text-white' },
  starting: { label: 'Démarrage', pill: 'bg-amber-400 text-amber-950' },
  crashed: { label: 'Planté', pill: 'bg-red-500 text-white' },
  stopped: { label: 'Arrêté', pill: 'bg-zinc-500/80 text-white' },
}

const day = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })

export const MinecraftCard: CardComponent<MinecraftProps, MinecraftData> = ({ card, result }) => {
  const { title, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null
  const state = data ? (STATES[data.server.state] ?? STATES.stopped!) : null

  return (
    <div className="flex h-full flex-col gap-2">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon={icon} name={title} />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        {data && state && (
          <>
            <span className={`shrink-0 rounded-full px-2 py-0.5 text-[0.65rem] font-semibold ${state.pill}`}>{state.label}</span>
            {data.server.state === 'online' && (
              <span className="min-w-0 truncate text-xs text-zinc-600 tabular-nums dark:text-slate-300">
                {data.server.online}/{data.server.max}
                {data.server.players.length > 0 && ` · ${data.server.players.join(', ')}`}
              </span>
            )}
          </>
        )}
        <span className="flex-1" />
        {data && (
          <ExternalLink href={data.server.link} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
            Crafty →
          </ExternalLink>
        )}
      </header>

      {data === null ? (
        <div aria-busy="true" className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      ) : (
        <ExternalLink
          href={data.image.url}
          aria-label={`Voir le rendu de ${card.title ?? title} en grand`}
          className="relative min-h-0 flex-1 overflow-hidden rounded-lg bg-gradient-to-b from-sky-200/70 to-sky-50/40 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:from-sky-950/60 dark:to-slate-900/40"
        >
          <img src={data.image.url} alt="" className="size-full object-contain p-1" />
          {data.image.renderedAt && (
            <span className="absolute right-1.5 bottom-1.5 rounded bg-black/45 px-1.5 py-0.5 text-[0.65rem] text-white backdrop-blur-sm">
              Rendu du {day.format(new Date(data.image.renderedAt))}
            </span>
          )}
        </ExternalLink>
      )}
    </div>
  )
}
