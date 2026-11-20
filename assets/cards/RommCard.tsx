import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { formatNumber } from '../ui/format'
import { MediaSlider } from '../ui/MediaSlider'
import { MediaTile } from '../ui/MediaTile'
import { ServiceIcon } from '../ui/ServiceIcon'

/** Props de RommCard::describe() côté PHP. */
export type RommProps = { title: string; service: string; url: string; icon: string | null }

/** Données de RommCard::fetch() côté PHP. Jaquettes publiques, lues directement sur RomM. */
export type RommData = {
  stats: { games: number; platforms: number; saves: number }
  games: { id: number; title: string; platform: string; cover: string | null; link: string; addedAt: string | null }[]
}

const WEEK = 7 * 86400_000

const count = (n: number, one: string, many: string) => `${formatNumber(n)} ${n > 1 ? many : one}`

export const RommCard: CardComponent<RommProps, RommData> = ({ card, result }) => {
  const { title, service, url, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null
  const now = Date.now()

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon={icon} name={service} />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        <span className="flex-1" />
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir {service} →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      ) : data.games.length === 0 ? (
        <p className="text-sm text-zinc-500 dark:text-slate-400">Aucun jeu pour l’instant.</p>
      ) : (
        <MediaSlider
          label="Derniers jeux ajoutés"
          previousLabel="Jeux précédents"
          nextLabel="Jeux suivants"
          aspect="aspect-[3/4]"
          items={data.games}
          itemKey={(g) => String(g.id)}
          renderItem={(g) => (
            <MediaTile
              href={g.link}
              image={g.cover}
              title={g.title}
              subtitle={g.platform}
              badge={
                g.addedAt && now - new Date(g.addedAt).getTime() < WEEK ? (
                  <span className="rounded-full bg-amber-400 px-1.5 py-0.5 text-[0.6rem] font-bold tracking-wide text-amber-950 uppercase shadow-sm">Nouveau</span>
                ) : null
              }
            />
          )}
        />
      )}

      {data && (
        <ul aria-label="Bibliothèque" className="mt-auto flex shrink-0 gap-1.5 overflow-hidden">
          {[count(data.stats.games, 'jeu', 'jeux'), count(data.stats.platforms, 'plateforme', 'plateformes'), count(data.stats.saves, 'sauvegarde', 'sauvegardes')].map((stat) => (
            <li key={stat} className="truncate rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-700 tabular-nums dark:bg-slate-800 dark:text-slate-300">
              {stat}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
