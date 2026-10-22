import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { formatNumber } from '../ui/format'
import { MediaSlider } from '../ui/MediaSlider'
import { MediaTile } from '../ui/MediaTile'
import { ServiceIcon } from '../ui/ServiceIcon'

/** Props de BrickTrackerCard::describe() côté PHP. */
export type BrickTrackerProps = { title: string; service: string; url: string; icon: string | null }

export type BrickSet = { id: string; number: string; name: string; theme: string | null; parts: number; minifigures: number; image: string; link: string }

/** Données de BrickTrackerCard::fetch() côté PHP. */
export type BrickTrackerData = {
  stats: { sets: number; parts: number; minifigures: number; missingParts: number; damagedParts: number }
  sets: BrickSet[]
}

export const BrickTrackerCard: CardComponent<BrickTrackerProps, BrickTrackerData> = ({ card, result }) => {
  const { title, service, url, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null
  const toFind = data ? data.stats.missingParts + data.stats.damagedParts : 0

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon={icon} name={service} />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        {data && (
          <span className="min-w-0 truncate text-xs text-zinc-500 tabular-nums dark:text-slate-400">
            {formatNumber(data.stats.sets)} sets · {formatNumber(data.stats.parts)} pièces · {formatNumber(data.stats.minifigures)} minifigs
          </span>
        )}
        <span className="flex-1" />
        {toFind > 0 && (
          <ExternalLink
            href={`${url.replace(/\/$/, '')}/parts/problem`}
            title={`${data!.stats.missingParts} manquantes, ${data!.stats.damagedParts} abîmées`}
            className="shrink-0 rounded-full bg-amber-400 px-2 py-0.5 text-xs font-semibold text-amber-950 hover:bg-amber-300"
          >
            {toFind} {toFind > 1 ? 'pièces' : 'pièce'} à retrouver
          </ExternalLink>
        )}
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      ) : (
        <MediaSlider
          label="Derniers sets"
          previousLabel="Sets précédents"
          nextLabel="Sets suivants"
          aspect="aspect-square"
          items={data.sets}
          itemKey={(s) => s.id}
          renderItem={(s) => (
            <MediaTile
              href={s.link}
              image={s.image}
              fit="contain"
              title={s.name}
              subtitle={[s.theme, `${formatNumber(s.parts)} pièces`].filter(Boolean).join(' · ')}
              badge={<span className="rounded bg-black/60 px-1.5 py-0.5 text-[0.6rem] font-semibold text-white tabular-nums backdrop-blur-sm">{s.number}</span>}
            />
          )}
        />
      )}
    </div>
  )
}
