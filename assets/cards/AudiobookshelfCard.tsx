import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { formatDuration, formatNumber } from '../ui/format'
import { MediaSlider } from '../ui/MediaSlider'
import { MediaTile } from '../ui/MediaTile'
import { ServiceIcon } from '../ui/ServiceIcon'

/** Props d'AudiobookshelfCard::describe() côté PHP. */
export type AudiobookshelfProps = { title: string; service: string; url: string; icon: string | null }

export type ListeningItem = { id: string; title: string; author: string | null; cover: string; link: string; progress: number; remaining: number; lastPlayed: string }

/** Données d'AudiobookshelfCard::fetch() côté PHP. */
export type AudiobookshelfData = {
  listening: ListeningItem[]
  recent: { id: string; title: string; author: string | null; cover: string; link: string; duration: number }[]
  total: number
}

export const AudiobookshelfCard: CardComponent<AudiobookshelfProps, AudiobookshelfData> = ({ card, result }) => {
  const { title, service, url, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null
  // Une écoute par rangée de la carte au plus : les couvertures gardent de la place.
  const shown = data?.listening.slice(0, Math.max(1, card.rows)) ?? []
  const hidden = (data?.listening.length ?? 0) - shown.length

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon={icon} name={service} />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        {data && <span className="shrink-0 text-xs text-zinc-500 tabular-nums dark:text-slate-400">{formatNumber(data.total)} livres</span>}
        <span className="flex-1" />
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir {service} →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="flex min-h-0 flex-1 flex-col gap-3">
          <div className="h-14 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
          <div className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
        </div>
      ) : (
        <>
          {shown.length > 0 && (
            <section aria-label="Reprendre l'écoute" className="flex flex-col gap-1.5">
              {shown.map((item) => (
                <Listening key={item.id} item={item} />
              ))}
              {hidden > 0 && (
                <span title={data.listening.slice(shown.length).map((i) => i.title).join(', ')} className="px-1 text-xs text-zinc-500 dark:text-slate-400">
                  +{hidden} {hidden > 1 ? 'autres écoutes en cours' : 'autre écoute en cours'}
                </span>
              )}
            </section>
          )}
          <MediaSlider
            label="Derniers livres audio"
            previousLabel="Livres précédents"
            nextLabel="Livres suivants"
            aspect="aspect-square"
            items={data.recent}
            itemKey={(b) => b.id}
            renderItem={(b) => <MediaTile href={b.link} image={b.cover} title={b.title} subtitle={b.author} />}
          />
        </>
      )}
    </div>
  )
}

function Listening({ item }: { item: ListeningItem }) {
  const percent = Math.round(item.progress * 100)

  return (
    <ExternalLink
      href={item.link}
      title={`${item.title}${item.author ? ` — ${item.author}` : ''}`}
      className="flex items-center gap-2.5 rounded-lg bg-teal-500/8 px-2 py-1.5 ring-1 ring-teal-500/15 hover:bg-teal-500/12 focus-visible:outline-2 focus-visible:outline-indigo-500"
    >
      <img src={item.cover} alt="" draggable={false} className="size-11 shrink-0 rounded object-cover" />
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="flex min-w-0 items-baseline gap-1.5">
          <span className="truncate text-sm font-semibold text-zinc-900 dark:text-slate-50">{item.title}</span>
          {item.author && <span className="truncate text-xs text-zinc-500 dark:text-slate-400">{item.author}</span>}
        </span>
        <span className="flex items-center gap-2 text-[0.7rem] text-zinc-500 tabular-nums dark:text-slate-400">
          <span
            role="progressbar"
            aria-label={`${item.title} : ${percent} %`}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={percent}
            className="h-1 min-w-8 flex-1 overflow-hidden rounded-full bg-teal-500/15"
          >
            <span className="block h-full rounded-full bg-teal-500" style={{ width: `${percent}%` }} />
          </span>
          <span className="shrink-0 font-medium text-zinc-700 dark:text-slate-200">{percent} %</span>
          <span className="shrink-0">reste {formatDuration(item.remaining)}</span>
        </span>
      </span>
    </ExternalLink>
  )
}
