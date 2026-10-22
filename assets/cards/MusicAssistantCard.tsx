import type { CSSProperties } from 'react'
import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { formatClock } from '../ui/format'
import { PauseIcon, SpeakerIcon } from '../ui/icons'
import { MediaSlider } from '../ui/MediaSlider'
import { MediaTile } from '../ui/MediaTile'
import { ServiceIcon } from '../ui/ServiceIcon'

/** Props de MusicAssistantCard::describe() côté PHP. */
export type MusicAssistantProps = { title: string; service: string; url: string; icon: string | null }

/** Données de MusicAssistantCard::fetch() côté PHP. */
export type MusicAssistantData = { recent: { name: string; type: string; image: string | null }[] }

/** Une enceinte du flux `speakers` ; titre et position seulement en lecture ou en pause. */
export type SpeakerItem = {
  id: string
  name: string
  state: string
  title?: string
  artist?: string | null
  album?: string | null
  image?: string | null
  duration?: number | null
  elapsed?: number
  accent?: string | null
}

const TYPES: Record<string, string> = { track: 'Titre', album: 'Album', artist: 'Artiste', playlist: 'Playlist', radio: 'Radio', audiobook: 'Livre audio', podcast: 'Podcast' }

export const MusicAssistantCard: CardComponent<MusicAssistantProps, MusicAssistantData> = ({ card, result, feeds }) => {
  const { title, service, url, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null
  const feed = feeds?.speakers
  const speakers = feed?.status === 'ok' ? ((feed.data as { speakers: SpeakerItem[] }).speakers ?? []) : []
  const playing = speakers.filter((s) => s.title !== undefined)
  const idle = speakers.filter((s) => s.title === undefined)

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon={icon} name={service} />
        </span>
        <h3 className="min-w-0 flex-1 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir →
        </ExternalLink>
      </header>

      {playing.length > 0 && (
        <section aria-label="En cours de lecture" className="flex flex-col gap-1.5">
          {playing.map((s) => (
            <Playing key={s.id} speaker={s} />
          ))}
        </section>
      )}
      {idle.length > 0 && (
        <p className="flex items-center gap-1.5 truncate text-xs text-zinc-500 dark:text-slate-400">
          <SpeakerIcon className="size-3.5 shrink-0" />
          <span className="truncate">En veille : {idle.map((s) => s.name).join(', ')}</span>
        </p>
      )}

      {data === null ? (
        <div aria-busy="true" className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      ) : (
        <MediaSlider
          label="Écoutés récemment"
          previousLabel="Écoutes précédentes"
          nextLabel="Écoutes suivantes"
          aspect="aspect-square"
          items={data.recent}
          itemKey={(i) => `${i.type}:${i.name}`}
          renderItem={(i) => <MediaTile href={url} image={i.image} title={i.name} subtitle={TYPES[i.type] ?? i.type} />}
        />
      )}
    </div>
  )
}

/** Ligne d'une enceinte qui joue, teintée avec la couleur de la pochette (--accent). */
function Playing({ speaker }: { speaker: SpeakerItem }) {
  const paused = speaker.state === 'paused'
  const duration = speaker.duration ?? 0
  const elapsed = speaker.elapsed ?? 0
  const percent = duration > 0 ? Math.round((elapsed / duration) * 100) : 0

  return (
    <div
      style={{ '--accent': speaker.accent ?? '#6366f1' } as CSSProperties}
      title={[speaker.title, speaker.artist, speaker.album].filter(Boolean).join(' — ')}
      className="flex items-center gap-2.5 rounded-lg bg-[color-mix(in_srgb,var(--accent)_10%,transparent)] px-2 py-1.5 ring-1 ring-[color-mix(in_srgb,var(--accent)_25%,transparent)]"
    >
      <span className="relative size-11 shrink-0 overflow-hidden rounded bg-zinc-200 dark:bg-slate-800">
        {speaker.image && <img src={speaker.image} alt="" className="size-full object-cover" />}
        {paused && (
          <span aria-label="En pause" className="absolute inset-0 grid place-items-center bg-black/45 text-white">
            <PauseIcon className="size-3.5" />
          </span>
        )}
      </span>
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="flex min-w-0 items-baseline gap-1.5">
          <span className="truncate text-sm font-semibold text-zinc-900 dark:text-slate-50">{speaker.title}</span>
          {speaker.artist && <span className="truncate text-xs text-zinc-500 dark:text-slate-400">{speaker.artist}</span>}
        </span>
        <span className="flex items-center gap-2 text-[0.7rem] text-zinc-500 tabular-nums dark:text-slate-400">
          <span className="flex shrink-0 items-center gap-1 font-medium text-zinc-700 dark:text-slate-200">
            <SpeakerIcon className="size-3" />
            {speaker.name}
          </span>
          {duration > 0 && (
            <>
              <span
                role="progressbar"
                aria-label={`${speaker.title} : ${percent} %`}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                className="h-1 min-w-8 flex-1 overflow-hidden rounded-full bg-[color-mix(in_srgb,var(--accent)_20%,transparent)]"
              >
                <span className={`block h-full rounded-full ${paused ? 'bg-zinc-400' : 'bg-(--accent)'}`} style={{ width: `${percent}%` }} />
              </span>
              <span className="shrink-0">
                {formatClock(elapsed)} / {formatClock(duration)}
              </span>
            </>
          )}
        </span>
      </span>
    </div>
  )
}
