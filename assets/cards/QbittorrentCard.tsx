import type { ReactNode } from 'react'
import type { CardComponent } from './registry'
import { useSampleHistory } from '../hooks/useSampleHistory'
import { formatBytes, formatNumber, formatRelative, formatSpeed } from '../ui/format'
import { AlertIcon, ArrowDownIcon, ArrowUpIcon, CheckIcon, ClockIcon, PauseIcon, RefreshIcon } from '../ui/icons'
import { ServiceIcon } from '../ui/ServiceIcon'
import { Sparkline } from '../ui/Sparkline'
import { ExternalLink } from '../ui/ExternalLink'

/** Props de QbittorrentCard::describe() côté PHP. */
export type QbittorrentProps = {
  title: string
  service: string
  url: string
  icon: string | null
}

export type TorrentStatus = 'downloading' | 'stalled' | 'queued' | 'seeding' | 'paused' | 'done' | 'checking' | 'error' | 'unknown'

export type TorrentItem = {
  name: string
  size: number
  progress: number
  status: TorrentStatus
  addedAt: string
  completedAt: string | null
}

/** Données de QbittorrentCard::fetch() côté PHP. */
export type QbittorrentData = {
  totals: { downloaded: number; uploaded: number; ratio: number | null; downloadSpeed: number; uploadSpeed: number }
  torrents: TorrentItem[]
}

/** Mesures gardées pour les courbes : ~10 min au rythme de 30 s. */
const HISTORY = 20

// Le libellé reste dans la couleur du texte ; l'icône porte la couleur.
const STATUS: Record<TorrentStatus, { label: string; icon: ReactNode }> = {
  downloading: { label: 'Téléchargement', icon: <ArrowDownIcon className="size-3.5 text-down" /> },
  seeding: { label: 'Partage', icon: <ArrowUpIcon className="size-3.5 text-up" /> },
  stalled: { label: 'Sans source', icon: <ClockIcon className="size-3.5 text-amber-600 dark:text-amber-400" /> },
  queued: { label: 'En file', icon: <ClockIcon className="size-3.5 text-zinc-400" /> },
  paused: { label: 'En pause', icon: <PauseIcon className="size-3.5 text-zinc-400" /> },
  done: { label: 'Terminé', icon: <CheckIcon className="size-3.5 text-zinc-400" /> },
  checking: { label: 'Vérification', icon: <RefreshIcon className="size-3.5 text-zinc-400" /> },
  error: { label: 'Erreur', icon: <AlertIcon className="size-3.5 text-rose-600 dark:text-rose-400" /> },
  unknown: { label: 'État inconnu', icon: <AlertIcon className="size-3.5 text-zinc-400" /> },
}

export const QbittorrentCard: CardComponent<QbittorrentProps, QbittorrentData> = ({ card, result }) => {
  const { title, service, url, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null
  const speeds = useSampleHistory(data ? result!.fetchedAt : null, data ? { down: data.totals.downloadSpeed, up: data.totals.uploadSpeed } : null, HISTORY)

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

      {data === null ? (
        <div aria-busy="true" className="flex min-h-0 flex-1 flex-col gap-3">
          <div className="grid h-20 grid-cols-2 gap-3">
            <div className="animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
            <div className="animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
          </div>
          <div className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
        </div>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3">
            <TransferTile
              label="Reçu"
              total={data.totals.downloaded}
              speed={data.totals.downloadSpeed}
              history={speeds.map((s) => s.down)}
              icon={<ArrowDownIcon className="size-4" />}
              tone="down"
            />
            <TransferTile
              label="Envoyé"
              total={data.totals.uploaded}
              speed={data.totals.uploadSpeed}
              history={speeds.map((s) => s.up)}
              icon={<ArrowUpIcon className="size-4" />}
              tone="up"
            />
          </div>
          <RatioBar totals={data.totals} />
          {data.torrents.length === 0 ? (
            <p className="grid min-h-0 flex-1 place-items-center text-sm text-zinc-500 dark:text-slate-400">Aucun téléchargement</p>
          ) : (
            // Colonne qui passe à la ligne : une ligne qui ne tient pas entière part
            // dans une 2e colonne, hors de la carte. Jamais de ligne coupée.
            <ul className="flex min-h-0 flex-1 flex-col flex-wrap content-start gap-x-8 gap-y-2 overflow-hidden">
              {data.torrents.map((t) => (
                <TorrentRow key={`${t.name}-${t.addedAt}`} torrent={t} />
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  )
}

// Classes écrites en entier : Tailwind ne détecte pas `text-${tone}`.
const TONES = {
  down: { chip: 'bg-down/10 text-down', line: 'text-down' },
  up: { chip: 'bg-up/10 text-up', line: 'text-up' },
}

function TransferTile(props: { label: string; total: number; speed: number; history: number[]; icon: ReactNode; tone: keyof typeof TONES }) {
  const tone = TONES[props.tone]

  // Une seule ligne : icône, total, courbe du débit, débit actuel.
  return (
    <div role="group" aria-label={props.label} className="flex items-center gap-2 rounded-xl bg-zinc-50 p-2.5 tabular-nums dark:bg-slate-800/60">
      <span className={`grid size-8 shrink-0 place-items-center rounded-lg ${tone.chip}`}>{props.icon}</span>
      <div className="min-w-0 shrink-0">
        <p className="text-xs text-zinc-500 dark:text-slate-400">{props.label}</p>
        <p className="text-base leading-5 font-semibold whitespace-nowrap text-zinc-900 dark:text-slate-50">{formatBytes(props.total)}</p>
      </div>
      <div className="h-7 min-w-8 flex-1 px-1">
        <Sparkline values={props.history} label={`Débit ${props.label.toLowerCase()} sur les dernières minutes, maximum ${formatSpeed(Math.max(0, ...props.history))}`} className={`h-full ${tone.line}`} />
      </div>
      <p className="shrink-0 text-right text-xs whitespace-nowrap text-zinc-600 dark:text-slate-300">{formatSpeed(props.speed)}</p>
    </div>
  )
}

function RatioBar({ totals }: { totals: QbittorrentData['totals'] }) {
  const empty = totals.downloaded + totals.uploaded === 0
  const label = `Ratio ${totals.ratio !== null ? formatNumber(totals.ratio) : '—'} : ${formatBytes(totals.downloaded)} reçus, ${formatBytes(totals.uploaded)} envoyés`

  return (
    <div className="flex items-center gap-2 text-xs tabular-nums">
      <div role="img" aria-label={label} title={label} className="flex h-2 flex-1 gap-0.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-slate-800">
        {!empty && (
          <>
            <span className="rounded-l-full bg-down" style={{ flexGrow: totals.downloaded }} />
            <span className="rounded-r-full bg-up" style={{ flexGrow: totals.uploaded }} />
          </>
        )}
      </div>
      {totals.ratio !== null && (
        <span className="shrink-0 font-medium text-zinc-700 dark:text-slate-200" aria-hidden="true">
          ×{formatNumber(totals.ratio)}
        </span>
      )}
    </div>
  )
}

function TorrentRow({ torrent }: { torrent: TorrentItem }) {
  const status = STATUS[torrent.status]
  const inProgress = torrent.progress < 1
  const percent = Math.floor(torrent.progress * 100)

  // Une ligne par téléchargement ; la 2e (barre de progression) seulement s'il est en cours.
  return (
    <li className="flex w-full flex-col gap-1">
      <div className="flex items-center gap-2 text-sm">
        {status.icon}
        <span className="min-w-0 flex-1 truncate text-zinc-800 dark:text-slate-200" title={torrent.name}>
          {torrent.name}
        </span>
        <span className="shrink-0 text-xs text-zinc-500 tabular-nums dark:text-slate-400">
          {status.label} · {formatBytes(torrent.size)} · {formatRelative(torrent.completedAt ?? torrent.addedAt, undefined, 'short')}
        </span>
      </div>
      {inProgress && (
        <div className="flex items-center gap-2 pl-5.5 text-xs text-zinc-500 tabular-nums dark:text-slate-400">
          <span role="progressbar" aria-label={torrent.name} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent} className="h-1 flex-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-slate-800">
            <span className="block h-full rounded-full bg-down" style={{ width: `${percent}%` }} />
          </span>
          <span className="w-9 shrink-0 text-right">{percent} %</span>
        </div>
      )}
    </li>
  )
}
