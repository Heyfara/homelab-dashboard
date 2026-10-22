import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { MediaSlider } from '../ui/MediaSlider'
import { MediaTile } from '../ui/MediaTile'
import { ServiceIcon } from '../ui/ServiceIcon'

/** Props de CalibreWebCard::describe() côté PHP. */
export type CalibreWebProps = { title: string; service: string; url: string; icon: string | null }

/** Données de CalibreWebCard::fetch() côté PHP. Couvertures via le relais signé. */
export type CalibreWebData = {
  books: { id: number; title: string; author: string | null; cover: string | null; link: string; addedAt: string | null }[]
}

const WEEK = 7 * 86400_000

export const CalibreWebCard: CardComponent<CalibreWebProps, CalibreWebData> = ({ card, result }) => {
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
      ) : data.books.length === 0 ? (
        <p className="text-sm text-zinc-500 dark:text-slate-400">Aucun livre pour l’instant.</p>
      ) : (
        <MediaSlider
          label="Derniers ebooks"
          previousLabel="Livres précédents"
          nextLabel="Livres suivants"
          items={data.books}
          itemKey={(b) => String(b.id)}
          renderItem={(b) => (
            <MediaTile
              href={b.link}
              image={b.cover}
              title={b.title}
              subtitle={b.author}
              badge={
                b.addedAt && now - new Date(b.addedAt).getTime() < WEEK ? (
                  <span className="rounded-full bg-amber-400 px-1.5 py-0.5 text-[0.6rem] font-bold tracking-wide text-amber-950 uppercase shadow-sm">Nouveau</span>
                ) : null
              }
            />
          )}
        />
      )}
    </div>
  )
}
