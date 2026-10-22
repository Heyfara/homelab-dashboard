import type { CardComponent } from './registry'
import { formatClock } from '../ui/format'
import { PauseIcon } from '../ui/icons'
import { MediaSlider } from '../ui/MediaSlider'
import { MediaTile } from '../ui/MediaTile'
import { ServiceIcon } from '../ui/ServiceIcon'
import { ExternalLink } from '../ui/ExternalLink'

/** Props de JellyfinLatestCard::describe() côté PHP. */
export type JellyfinLatestProps = {
  title: string
  service: string
  url: string
  icon: string | null
}

export type JellyfinLatestItem = {
  id: string
  title: string
  subtitle: string | null
  image: string | null
  link: string
  addedAt: string | null
}

/** Données de JellyfinLatestCard::fetch() côté PHP. */
export type JellyfinLatestData = { items: JellyfinLatestItem[] }

/** Une lecture en cours : flux « sessions » (JellyfinLatestCard::fetchFeed). */
export type JellyfinSessionItem = {
  id: string
  user: string
  device: string
  client: string
  title: string
  subtitle: string | null
  image: string | null
  link: string
  position: number
  duration: number
  paused: boolean
  transcoding: boolean
}

/**
 * Derniers ajouts sous forme d'affiches 2:3, sur une seule ligne : les affiches
 * ont la hauteur de la carte et celles qui ne tiennent pas en largeur passent
 * à la ligne suivante, masquée. Jamais d'affiche coupée.
 */

export const JellyfinLatestCard: CardComponent<JellyfinLatestProps, JellyfinLatestData> = ({ card, result, feeds }) => {
  const { title, service, url, icon } = card.props
  const items = result?.status === 'ok' ? result.data.items : null
  const sessionsFeed = feeds?.sessions
  const sessions = sessionsFeed?.status === 'ok' ? ((sessionsFeed.data as { sessions: JellyfinSessionItem[] }).sessions ?? []) : []
  // Une lecture par rangée de la carte au plus : les affiches gardent de la place.
  const shown = sessions.slice(0, Math.max(1, card.rows))
  const hidden = sessions.slice(shown.length)

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-6 shrink-0 [&>*]:size-6 [&>span]:rounded-md [&>span]:text-xs">
          <ServiceIcon icon={icon} name={service} />
        </span>
        <h3 className="min-w-0 flex-1 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir {service} →
        </ExternalLink>
      </header>

      {sessions.length > 0 && (
        <section aria-label="Lectures en cours" className="flex flex-col gap-1.5">
          {shown.map((s) => (
            <NowPlaying key={s.id} session={s} />
          ))}
          {hidden.length > 0 && (
            <span title={hidden.map((s) => `${s.user} : ${s.title}`).join(', ')} className="px-1 text-xs text-zinc-500 dark:text-slate-400">
              +{hidden.length} {hidden.length > 1 ? 'autres lectures' : 'autre lecture'}
            </span>
          )}
        </section>
      )}

      {items === null ? (
        <div aria-busy="true" className="flex min-h-0 flex-1 flex-wrap content-start gap-3 overflow-hidden">
          {Array.from({ length: 8 }, (_, i) => (
            <div key={i} className="aspect-[2/3] h-full animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
          ))}
        </div>
      ) : items.length === 0 ? (
        <p className="grid min-h-0 flex-1 place-items-center text-sm text-zinc-500 dark:text-slate-400">Rien de nouveau</p>
      ) : (
        <MediaSlider
          label="Derniers ajouts"
          previousLabel="Ajouts précédents"
          nextLabel="Ajouts suivants"
          items={items}
          itemKey={(item) => item.id}
          renderItem={(item) => <MediaTile href={item.link} image={item.image} title={item.title} subtitle={item.subtitle} />}
        />
      )}
    </div>
  )
}

function NowPlaying({ session }: { session: JellyfinSessionItem }) {
  const percent = session.duration > 0 ? Math.round((session.position / session.duration) * 100) : 0

  return (
    <ExternalLink
      href={session.link}
      title={`${session.title}${session.subtitle ? ` · ${session.subtitle}` : ''} — ${session.user} sur ${session.device} (${session.client})`}
      className="flex items-center gap-2.5 rounded-lg bg-indigo-500/8 px-2 py-1.5 ring-1 ring-indigo-500/15 hover:bg-indigo-500/12 focus-visible:outline-2 focus-visible:outline-indigo-500"
    >
      <span className="relative h-11 w-[1.85rem] shrink-0 overflow-hidden rounded bg-zinc-200 dark:bg-slate-800">
        {session.image && <img src={session.image} alt="" className="size-full object-cover" />}
        {session.paused && (
          <span aria-label="En pause" className="absolute inset-0 grid place-items-center bg-black/45 text-white">
            <PauseIcon className="size-3.5" />
          </span>
        )}
      </span>
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="flex min-w-0 items-center gap-1.5">
          <span className="truncate text-sm font-semibold text-zinc-900 dark:text-slate-50">{session.title}</span>
          {session.subtitle && <span className="truncate text-xs text-zinc-500 dark:text-slate-400">{session.subtitle}</span>}
          {session.transcoding && (
            <span title="Le serveur convertit la vidéo" className="shrink-0 rounded-full bg-amber-50 px-1.5 text-[0.6rem] font-medium text-amber-800 ring-1 ring-amber-200 dark:bg-amber-400/15 dark:text-amber-300 dark:ring-amber-400/30">
              Transcodage
            </span>
          )}
        </span>
        <span className="flex items-center gap-2 text-[0.7rem] text-zinc-500 tabular-nums dark:text-slate-400">
          <span className="truncate">
            {session.user} · {session.device}
          </span>
          <span
            role="progressbar"
            aria-label={`${session.title} : ${percent} %`}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={percent}
            className="h-1 min-w-8 flex-1 overflow-hidden rounded-full bg-indigo-500/15"
          >
            <span className={`block h-full rounded-full ${session.paused ? 'bg-zinc-400' : 'bg-indigo-500'}`} style={{ width: `${percent}%` }} />
          </span>
          <span className="shrink-0">
            {formatClock(session.position)} / {formatClock(session.duration)}
          </span>
        </span>
      </span>
    </ExternalLink>
  )
}
