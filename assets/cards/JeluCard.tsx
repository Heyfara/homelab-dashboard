import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { MediaSlider } from '../ui/MediaSlider'
import { MediaTile } from '../ui/MediaTile'
import { ServiceIcon } from '../ui/ServiceIcon'

/** Props de JeluCard::describe() côté PHP. */
export type JeluProps = { title: string; service: string; url: string; icon: string | null }

export type JeluBook = { reader: string; bookId: string; title: string; authors: string | null; cover: string | null; link: string; percent: number | null; date: string | null }

/** Données de JeluCard::fetch() côté PHP. */
export type JeluData = {
  year: number
  readers: { name: string; finished: number }[]
  reading: JeluBook[]
  finished: JeluBook[]
}

export const JeluCard: CardComponent<JeluProps, JeluData> = ({ card, result }) => {
  const { title, service, url, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null
  // Une lecture par rangée de la carte au plus : le slider garde de la place.
  const shown = data?.reading.slice(0, Math.max(1, card.rows)) ?? []
  const hidden = (data?.reading.length ?? 0) - shown.length

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon={icon} name={service} />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        {data && data.readers.length > 0 && (
          <ul aria-label={`Livres lus en ${data.year}`} title={`Livres terminés en ${data.year}`} className="flex min-w-0 gap-1 overflow-hidden">
            {data.readers.map((r) => (
              <li key={r.name} className="flex shrink-0 items-center gap-1 rounded-full bg-amber-500/12 py-0.5 pr-1 pl-2 text-xs text-amber-900 dark:text-amber-200">
                {r.name}
                <span className="rounded-full bg-amber-500/25 px-1.5 font-semibold tabular-nums">{r.finished}</span>
              </li>
            ))}
          </ul>
        )}
        <span className="flex-1" />
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      ) : data.reading.length === 0 && data.finished.length === 0 ? (
        <p className="text-sm text-zinc-500 dark:text-slate-400">Aucune lecture en cours ni terminée cette année.</p>
      ) : (
        <>
          {shown.length > 0 && (
            <section aria-label="En ce moment" className="flex flex-col gap-1.5">
              {shown.map((b) => (
                <Reading key={`${b.reader}:${b.bookId}`} book={b} />
              ))}
              {hidden > 0 && (
                <span title={data.reading.slice(shown.length).map((b) => `${b.reader} : ${b.title}`).join('\n')} className="px-1 text-xs text-zinc-500 dark:text-slate-400">
                  +{hidden} {hidden > 1 ? 'autres lectures en cours' : 'autre lecture en cours'}
                </span>
              )}
            </section>
          )}
          {data.finished.length > 0 && (
            <MediaSlider
              label={`Terminés en ${data.year}`}
              previousLabel="Livres précédents"
              nextLabel="Livres suivants"
              items={data.finished}
              itemKey={(b) => `${b.reader}:${b.bookId}`}
              renderItem={(b) => <MediaTile href={b.link} image={b.cover} title={b.title} subtitle={b.reader} />}
            />
          )}
        </>
      )}
    </div>
  )
}

function Reading({ book }: { book: JeluBook }) {
  return (
    <ExternalLink
      href={book.link}
      title={`${book.reader} lit « ${book.title} »${book.authors ? ` de ${book.authors}` : ''}`}
      className="flex items-center gap-2.5 rounded-lg bg-amber-500/8 px-2 py-1.5 ring-1 ring-amber-500/15 hover:bg-amber-500/12 focus-visible:outline-2 focus-visible:outline-indigo-500"
    >
      <span className="h-11 w-[1.85rem] shrink-0 overflow-hidden rounded bg-zinc-200 dark:bg-slate-800">
        {book.cover && <img src={book.cover} alt="" draggable={false} className="size-full object-cover" />}
      </span>
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="flex min-w-0 items-baseline gap-1.5">
          <span className="truncate text-sm font-semibold text-zinc-900 dark:text-slate-50">{book.title}</span>
          {book.authors && <span className="truncate text-xs text-zinc-500 dark:text-slate-400">{book.authors}</span>}
        </span>
        <span className="flex items-center gap-2 text-[0.7rem] text-zinc-500 tabular-nums dark:text-slate-400">
          <span className="shrink-0 rounded-full bg-amber-500/20 px-1.5 font-medium text-amber-900 dark:text-amber-200">{book.reader}</span>
          {/* 0 % : valeur par défaut de Jelu quand rien n'est saisi, pas un avancement. */}
          {book.percent !== null && book.percent > 0 && (
            <>
              <span
                role="progressbar"
                aria-label={`${book.title} : ${book.percent} %`}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={book.percent}
                className="h-1 min-w-8 flex-1 overflow-hidden rounded-full bg-amber-500/15"
              >
                <span className="block h-full rounded-full bg-amber-500" style={{ width: `${book.percent}%` }} />
              </span>
              <span className="shrink-0 font-medium text-zinc-700 dark:text-slate-200">{book.percent} %</span>
            </>
          )}
        </span>
      </span>
    </ExternalLink>
  )
}
