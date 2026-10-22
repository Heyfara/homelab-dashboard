import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { formatNumber } from '../ui/format'
import { PlayIcon } from '../ui/icons'
import { MediaSlider } from '../ui/MediaSlider'
import { ServiceIcon } from '../ui/ServiceIcon'

/** Props d'ImmichCard::describe() côté PHP. */
export type ImmichProps = { title: string; service: string; url: string; icon: string | null }

export type ImmichMemory = { id: string; year: number; yearsAgo: number; count: number; cover: string; link: string }
export type ImmichAsset = { id: string; video: boolean; takenAt: string | null; thumb: string; link: string }

/** Données d'ImmichCard::fetch() côté PHP. Les images passent par le relais signé. */
export type ImmichData = { stats: { images: number; videos: number }; memories: ImmichMemory[]; recent: ImmichAsset[] }

// takenAt est l'heure de l'appareil, sans fuseau : lue telle quelle.
const day = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })

export const ImmichCard: CardComponent<ImmichProps, ImmichData> = ({ card, result }) => {
  const { title, service, url, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon={icon} name={service} />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        {data && (
          <span className="shrink-0 truncate text-xs text-zinc-500 tabular-nums dark:text-slate-400">
            {formatNumber(data.stats.images)} photos · {formatNumber(data.stats.videos)} vidéos
          </span>
        )}
        <span className="flex-1" />
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir {service} →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      ) : (
        <>
          {data.memories.length > 0 && (
            <section aria-label="Souvenirs" className="flex gap-2">
              {data.memories.slice(0, 3).map((m) => (
                <Memory key={m.id} memory={m} />
              ))}
            </section>
          )}
          <MediaSlider
            label="Dernières photos"
            previousLabel="Photos précédentes"
            nextLabel="Photos suivantes"
            aspect="aspect-square"
            items={data.recent}
            itemKey={(a) => a.id}
            renderItem={(a) => <Photo asset={a} />}
          />
        </>
      )}
    </div>
  )
}

function Memory({ memory }: { memory: ImmichMemory }) {
  const ago = `Il y a ${memory.yearsAgo} ${memory.yearsAgo > 1 ? 'ans' : 'an'}`

  return (
    <ExternalLink
      href={memory.link}
      title={`${ago} (${memory.year})`}
      draggable={false}
      className="group relative h-20 min-w-0 flex-1 overflow-hidden rounded-lg bg-zinc-200 ring-1 ring-black/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500 dark:bg-slate-800 dark:ring-white/10"
    >
      <img src={memory.cover} alt="" loading="lazy" draggable={false} className="size-full object-cover transition duration-300 group-hover:scale-105 motion-reduce:transition-none" />
      <span className="absolute inset-0 flex flex-col justify-end bg-gradient-to-t from-black/80 via-black/30 to-transparent px-2 pb-1.5 text-white">
        <span className="text-sm leading-tight font-semibold">{ago}</span>
        <span className="text-[0.65rem] text-white/80">
          {memory.count} {memory.count > 1 ? 'photos' : 'photo'} · {memory.year}
        </span>
      </span>
    </ExternalLink>
  )
}

function Photo({ asset }: { asset: ImmichAsset }) {
  const label = `${asset.video ? 'Vidéo' : 'Photo'}${asset.takenAt ? ` du ${day.format(new Date(asset.takenAt))}` : ''}`

  return (
    <ExternalLink
      href={asset.link}
      aria-label={label}
      title={label}
      draggable={false}
      className="group relative block h-full overflow-hidden rounded-lg bg-zinc-200 ring-1 ring-black/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500 dark:bg-slate-800 dark:ring-white/10"
    >
      <img src={asset.thumb} alt="" loading="lazy" draggable={false} className="size-full object-cover transition duration-300 group-hover:scale-105 motion-reduce:transition-none" />
      {asset.video && (
        <span className="absolute top-1.5 right-1.5 grid size-6 place-items-center rounded-full bg-black/55 text-white backdrop-blur-sm">
          <PlayIcon className="size-3" />
        </span>
      )}
    </ExternalLink>
  )
}
