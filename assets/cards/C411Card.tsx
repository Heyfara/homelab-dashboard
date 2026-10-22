import type { ReactNode } from 'react'
import type { CardComponent } from './registry'
import { formatBytes, formatNumber } from '../ui/format'
import { ArrowDownIcon, ArrowUpIcon, CoinIcon, StarIcon } from '../ui/icons'
import { ExternalLink } from '../ui/ExternalLink'

/** Props de C411Card::describe() côté PHP. */
export type C411Props = { title: string; service: string; url: string; icon: string | null }

export type C411Voie = { key: 'seed' | 'upload' | 'specialization' | 'competitivity'; star: boolean; completed: number; total: number }

/** Données de C411Card::fetch() côté PHP. */
export type C411Data = {
  user: { name: string; avatar: string | null; memberSince: string | null }
  level: { level: number; xp: number; xpIntoLevel: number; xpToNextLevel: number; progress: number; badge: { emblem: string; dial: string } }
  class: null | {
    slug: string
    grade: { tier: 'bronze' | 'argent' | 'or' | string; level: number }
    stars: number
    questsCompleted: number
    questsTotal: number
    bonusPercent: number
    voies: C411Voie[]
  }
  team: { name: string; tag: string } | null
  zen: { balance: number; earned: number; spent: number }
  transfer: { uploaded: number; downloaded: number; ratio: number | null }
  download: { allowed: boolean; blockedReason: string | null }
  freeleech: { active: boolean; sources: string[] }
}

// Une couleur par voie (catégories), classes écrites en entier pour Tailwind.
const VOIES: Record<C411Voie['key'], { label: string; fill: string; track: string; dot: string }> = {
  seed: { label: 'Seed', fill: 'bg-voie-seed', track: 'bg-voie-seed/15', dot: 'bg-voie-seed' },
  upload: { label: 'Upload', fill: 'bg-voie-upload', track: 'bg-voie-upload/15', dot: 'bg-voie-upload' },
  specialization: { label: 'Spécialisation', fill: 'bg-voie-specialization', track: 'bg-voie-specialization/15', dot: 'bg-voie-specialization' },
  competitivity: { label: 'Compétitivité', fill: 'bg-voie-competitivity', track: 'bg-voie-competitivity/15', dot: 'bg-voie-competitivity' },
}


// Emblème : la couleur évoque le métal, le libellé dit le palier.
const TIERS: Record<string, { label: string; className: string }> = {
  bronze: { label: 'Bronze', className: 'bg-orange-100 text-orange-900 ring-orange-300 dark:bg-orange-950 dark:text-orange-200 dark:ring-orange-800' },
  argent: { label: 'Argent', className: 'bg-slate-100 text-slate-800 ring-slate-300 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-600' },
  or: { label: 'Or', className: 'bg-yellow-100 text-yellow-900 ring-yellow-300 dark:bg-yellow-950 dark:text-yellow-200 dark:ring-yellow-800' },
}

const ROMAN = ['', 'I', 'II', 'III', 'IV']

const BLOCKED: Record<string, string> = { insufficient_balance: 'solde insuffisant' }

/** « team_resident_mainstream » → « Team resident mainstream » : l'API ne donne pas le nom affiché. */
export function classLabel(slug: string): string {
  const words = slug.replaceAll('_', ' ').trim()
  return words.charAt(0).toUpperCase() + words.slice(1)
}

export const C411Card: CardComponent<C411Props, C411Data> = ({ card, result }) => {
  const { title, service, url, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null

  if (data === null) {
    return (
      <div aria-busy="true" className="flex h-full flex-col gap-3">
        <div className="h-6 w-1/2 animate-pulse rounded bg-zinc-100 dark:bg-slate-800" />
        <div className="flex gap-3">
          <div className="size-16 animate-pulse rounded-full bg-zinc-100 dark:bg-slate-800" />
          <div className="h-16 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
        </div>
        <div className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      </div>
    )
  }

  const { user, level, team } = data

  return (
    <div className="flex h-full flex-col gap-3 tabular-nums">
      <header className="-mx-4 -mt-4 flex items-center gap-2 bg-gradient-to-r from-emerald-500/15 via-cyan-500/10 to-transparent px-4 pt-3 pb-2">
        <h3 className="shrink-0 font-semibold text-zinc-900 dark:text-slate-50">
          {icon ? (
            // Logo en largeur (ex. https://c411.org/images/logo-md.png), affiché sans recadrage.
            <img src={icon} alt={card.title ?? title} className="h-7 w-auto max-w-32 object-contain" />
          ) : (
            (card.title ?? title)
          )}
        </h3>
        {data.freeleech.active && <span className="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">Freeleech</span>}
        {!data.download.allowed && (
          <span
            title={`Téléchargement bloqué${data.download.blockedReason ? ` : ${BLOCKED[data.download.blockedReason] ?? data.download.blockedReason}` : ''}`}
            className="min-w-0 truncate rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-800 dark:bg-rose-950 dark:text-rose-300"
          >
            Téléchargement bloqué{data.download.blockedReason ? ` : ${BLOCKED[data.download.blockedReason] ?? data.download.blockedReason}` : ''}
          </span>
        )}
        <span className="flex-1" />
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir {service} →
        </ExternalLink>
      </header>

      <div className="flex items-center gap-3">
        <LevelBadge level={level.level} badge={level.badge} />
        <div className="min-w-0 flex-1">
          <p className="flex items-center gap-2">
            {user.avatar && <img src={user.avatar} alt="" className="size-5 shrink-0 rounded-full object-cover" />}
            <span className="truncate text-lg leading-6 font-bold text-zinc-900 dark:text-slate-50">{user.name}</span>
            {team && (
              <span title={team.name} className="shrink-0 rounded bg-zinc-100 px-1.5 py-0.5 text-[0.65rem] font-semibold tracking-wide text-zinc-600 dark:bg-slate-800 dark:text-slate-300">
                {team.tag}
              </span>
            )}
          </p>
          {data.class ? (
            <>
              <p className="flex items-center gap-2">
                <span className="truncate font-semibold text-zinc-800 dark:text-slate-100">{classLabel(data.class.slug)}</span>
                <Grade tier={data.class.grade.tier} level={data.class.grade.level} />
              </p>
              <p className="mt-0.5 text-sm text-zinc-600 dark:text-slate-300">
                {data.class.questsCompleted} / {data.class.questsTotal} quêtes · {data.class.stars} <StarIcon filled className="inline size-3.5 -translate-y-px text-yellow-500" />
                {data.class.bonusPercent > 0 && ` · bonus +${formatNumber(data.class.bonusPercent)} %`}
              </p>
            </>
          ) : (
            <p className="text-sm text-zinc-500 dark:text-slate-400">Aucune classe choisie</p>
          )}
        </div>
        <Experience level={level} />
      </div>

      {data.class && (
        <ul className="grid grid-cols-2 gap-x-4 gap-y-2">
          {data.class.voies.map((v) => (
            <Voie key={v.key} voie={v} />
          ))}
        </ul>
      )}

      <dl className="mt-auto grid grid-cols-4 gap-2">
        <Stat icon={<CoinIcon className="size-3.5" />} tone={{ tile: 'bg-yellow-500/10', chip: 'bg-yellow-500/20 text-yellow-700 dark:text-yellow-400' }} label="Zen">
          {formatNumber(data.zen.balance)}
        </Stat>
        <Stat icon={<ArrowUpIcon className="size-3.5" />} tone={{ tile: 'bg-up/8', chip: 'bg-up/15 text-up' }} label="Envoyé">
          {formatBytes(data.transfer.uploaded)}
        </Stat>
        <Stat icon={<ArrowDownIcon className="size-3.5" />} tone={{ tile: 'bg-down/8', chip: 'bg-down/15 text-down' }} label="Reçu">
          {formatBytes(data.transfer.downloaded)}
        </Stat>
        <Stat icon={<span className="text-xs font-bold">×</span>} tone={{ tile: 'bg-fuchsia-500/8', chip: 'bg-fuchsia-500/15 text-fuchsia-700 dark:text-fuchsia-300' }} label="Ratio">
          {data.transfer.ratio !== null ? `×${formatNumber(data.transfer.ratio)}` : '—'}
        </Stat>
      </dl>
    </div>
  )
}

function Experience({ level }: { level: C411Data['level'] }) {
  const span = level.xpIntoLevel + level.xpToNextLevel
  const percent = Math.round(Math.min(1, Math.max(0, level.progress)) * 100)

  return (
    <div role="group" aria-label="Expérience" className="flex w-[38%] shrink-0 flex-col gap-1.5">
      <p className="flex items-baseline justify-between gap-2">
        <span className="text-sm font-semibold text-zinc-900 dark:text-slate-50">
          {formatNumber(level.xpIntoLevel)} / {formatNumber(span)} XP
        </span>
        <span className="text-xs text-zinc-500 dark:text-slate-400">encore {formatNumber(level.xpToNextLevel)}</span>
      </p>
      <span
        role="progressbar"
        aria-label={`Expérience du niveau ${level.level}`}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={percent}
        className="h-2 overflow-hidden rounded-full bg-emerald-500/15"
      >
        <span className="block h-full rounded-full bg-gradient-to-r from-emerald-500 to-green-600" style={{ width: `${percent}%` }} />
      </span>
      <p className="text-xs text-zinc-500 dark:text-slate-400">{formatNumber(level.xp)} XP au total</p>
    </div>
  )
}

/** Badge du site C411 : emblème en fond, cadran devant, niveau écrit par-dessus. */
function LevelBadge({ level, badge }: { level: number; badge: C411Data['level']['badge'] }) {
  return (
    <div role="img" aria-label={`Niveau ${level}`} title={`Niveau ${level}`} className="relative size-16 shrink-0">
      <img src={badge.emblem} alt="" className="absolute inset-0 size-full object-contain" />
      <img src={badge.dial} alt="" className="absolute inset-0 size-full object-contain" />
      <span aria-hidden="true" className="absolute inset-0 grid place-items-center text-lg font-extrabold text-white [text-shadow:0_1px_2px_rgb(0_0_0/0.7)]">
        {level}
      </span>
    </div>
  )
}

function Grade({ tier, level }: { tier: string; level: number }) {
  const t = TIERS[tier] ?? { label: tier, className: 'bg-zinc-100 text-zinc-700 ring-zinc-300 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-600' }

  return <span className={`shrink-0 rounded-full px-2 py-0.5 text-[0.65rem] font-semibold ring-1 ${t.className}`}>{`${t.label} ${ROMAN[level] ?? level}`}</span>
}

function Voie({ voie }: { voie: C411Voie }) {
  const ratio = voie.total > 0 ? voie.completed / voie.total : 0
  const style = VOIES[voie.key]

  return (
    <li className="flex flex-col gap-1">
      <div className="flex items-center gap-1.5 text-xs">
        <span className={`size-2 shrink-0 rounded-sm ${style.dot}`} aria-hidden="true" />
        <span className="text-zinc-700 dark:text-slate-200">{style.label}</span>
        {voie.star && (
          <span aria-label="étoile obtenue" title="Étoile obtenue">
            <StarIcon filled className="size-3 text-yellow-500" />
          </span>
        )}
        <span className="ml-auto text-zinc-500 dark:text-slate-400">
          {voie.completed} / {voie.total}
        </span>
      </div>
      <span className={`h-1.5 overflow-hidden rounded-full ${style.track}`}>
        <span data-voie-fill className={`block h-full rounded-full ${style.fill}`} style={{ width: `${Math.round(ratio * 100)}%` }} />
      </span>
    </li>
  )
}

function Stat({ icon, tone, label, children }: { icon: ReactNode; tone: { tile: string; chip: string }; label: string; children: ReactNode }) {
  return (
    <div className={`flex min-w-0 items-center gap-2 rounded-lg px-2 py-1.5 ${tone.tile}`}>
      <span className={`grid size-6 shrink-0 place-items-center rounded-md ${tone.chip}`} aria-hidden="true">
        {icon}
      </span>
      <div className="min-w-0">
        <dt className="text-[0.65rem] leading-3 text-zinc-500 dark:text-slate-400">{label}</dt>
        <dd className="truncate text-sm font-semibold text-zinc-900 dark:text-slate-50">{children}</dd>
      </div>
    </div>
  )
}
