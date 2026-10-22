import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { ServiceIcon } from '../ui/ServiceIcon'

/** Props de CraftyCard::describe() côté PHP. */
export type CraftyProps = { title: string; service: string; url: string; icon: string | null }

/** Données de CraftyCard::fetch() côté PHP. */
export type CraftyData = {
  playersOnline: number
  servers: {
    id: string
    name: string
    /** online, starting, stopped ou crashed */
    state: string
    online: number
    max: number
    players: string[]
    version: string | null
    motd: string | null
    worldSize: string | null
    startedAt: string | null
    public: boolean
    link: string
    /** data URI de l'icône du serveur */
    icon: string | null
  }[]
}

const STATES: Record<string, { label: string; pill: string }> = {
  online: { label: 'En ligne', pill: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' },
  starting: { label: 'Démarrage', pill: 'bg-amber-500/15 text-amber-700 dark:text-amber-300' },
  crashed: { label: 'Planté', pill: 'bg-red-500/15 text-red-700 dark:text-red-300' },
  stopped: { label: 'Arrêté', pill: 'bg-zinc-500/12 text-zinc-500 dark:text-slate-400' },
}

const UNITS: Record<string, string> = { B: 'o', KB: 'Ko', MB: 'Mo', GB: 'Go', TB: 'To' }
const decimal = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 })

/** Taille au format de Crafty (« 423.2MB ») en français (« 423,2 Mo ») ; inconnue : telle quelle. */
export function frenchSize(size: string): string {
  const m = size.trim().match(/^([\d.]+)\s*([KMGT]?B)$/i)

  return m ? `${decimal.format(Number(m[1]))} ${UNITS[m[2]!.toUpperCase()]}` : size
}

export const CraftyCard: CardComponent<CraftyProps, CraftyData> = ({ card, result }) => {
  const { title, service, url, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null

  return (
    // @container : sur une carte étroite (1 colonne), le résumé de l'en-tête s'efface.
    <div className="@container flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon={icon} name={service} />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        {data && (
          <span className="hidden shrink-0 text-xs text-zinc-500 tabular-nums @xs:inline dark:text-slate-400">
            {data.playersOnline === 0 ? 'personne en ligne' : `${data.playersOnline} ${data.playersOnline > 1 ? 'joueurs' : 'joueur'} en ligne`}
          </span>
        )}
        <span className="flex-1" />
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      ) : (
        <ul aria-label="Serveurs Minecraft" className="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto [scrollbar-width:thin]">
          {data.servers.map((s) => {
            const state = STATES[s.state] ?? STATES.stopped!
            const live = s.state === 'online'
            const details = [s.version, s.worldSize && frenchSize(s.worldSize)].filter(Boolean).join(' · ')

            return (
              <li key={s.id}>
                <ExternalLink
                  href={s.link}
                  title={[s.name, s.motd, details, s.players.length > 0 ? `Connectés : ${s.players.join(', ')}` : null].filter(Boolean).join('\n')}
                  className={`flex items-center gap-2.5 rounded-lg px-2 py-1.5 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-slate-800 ${s.state === 'stopped' ? 'opacity-70' : ''}`}
                >
                  {s.icon ? (
                    <img src={s.icon} alt="" className="size-8 shrink-0 rounded [image-rendering:pixelated]" />
                  ) : (
                    <span aria-hidden="true" className="grid size-8 shrink-0 place-items-center rounded bg-[linear-gradient(#5d9b3a_0_35%,#8a5a35_35%)] text-sm" />
                  )}
                  <span className="flex min-w-0 flex-1 flex-col">
                    <span className="flex min-w-0 items-center gap-1.5">
                      <span className="truncate text-sm font-semibold text-zinc-900 dark:text-slate-50">{s.name}</span>
                      {s.public && (
                        <span title="Ouvert sur Internet" className="shrink-0 rounded bg-sky-500/15 px-1 text-[0.6rem] font-semibold text-sky-700 uppercase dark:text-sky-300">
                          Internet
                        </span>
                      )}
                    </span>
                    {/* 2e ligne : les joueurs d'un serveur en ligne, sinon version et taille du monde. */}
                    <span className="truncate text-[0.7rem] text-zinc-500 tabular-nums dark:text-slate-400">
                      {live ? (
                        <>
                          <span className="font-semibold text-zinc-800 dark:text-slate-100">
                            {s.online}/{s.max}
                          </span>
                          {s.players.length > 0 && ` · ${s.players.join(', ')}`}
                        </>
                      ) : (
                        details
                      )}
                    </span>
                  </span>
                  <span className={`shrink-0 rounded-full px-2 py-0.5 text-[0.65rem] font-semibold ${state.pill}`}>{state.label}</span>
                </ExternalLink>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
