import type { CardComponent } from './registry'
import { formatBytes, formatDateTime, formatDuration, formatNumber, formatRelative } from '../ui/format'
import { AlertIcon, CheckIcon, ClockIcon } from '../ui/icons'
import { ServiceIcon } from '../ui/ServiceIcon'
import { StatTile } from '../ui/StatTile'

export type BackupEntry = {
  name: string
  label: string
  /** ok, late, missing ou unreachable */
  state: string
  /** Âge du dernier backup à l'instant de la réponse ; null s'il n'y en a pas */
  ageHours: number | null
  maxHours: number
  latest: string | null
  reason: string | null
}

/** Une nuit passée : sauvegardes en défaut ce jour-là (noms affichés). */
export type BackupNight = { date: string; problems: string[] }

/** Dernier passage de restic : sauvegarde locale puis copie vers B2. Résumé null si la sauvegarde a échoué. */
export type ResticRun = {
  finishedAt: string
  durationSeconds: number
  rc: number
  backupSeconds: number | null
  dataAdded: number | null
  filesNew: number | null
  filesChanged: number | null
  filesProcessed: number | null
  bytesProcessed: number | null
  /** null : copie pas tentée */
  copyRc: number | null
  copySeconds: number | null
}

/** Données de BackupsCard::fetch() côté PHP. */
export type BackupsData = {
  checkedAt: string
  stale: boolean
  ok: number
  problems: number
  total: number
  entries: BackupEntry[]
  nights: BackupNight[]
  restic: ResticRun | null
}

const STATES: Record<string, string> = { late: 'en retard', missing: 'absent', unreachable: 'inaccessible' }

const GREEN = 'bg-emerald-500/8 [&_[data-chip]]:bg-emerald-500/15 [&_[data-chip]]:text-emerald-600 dark:[&_[data-chip]]:text-emerald-400'
const RED = 'bg-red-500/8 [&_[data-chip]]:bg-red-500/15 [&_[data-chip]]:text-red-600 dark:[&_[data-chip]]:text-red-400'
const SKY = 'bg-sky-500/8 [&_[data-chip]]:bg-sky-500/15 [&_[data-chip]]:text-sky-600 dark:[&_[data-chip]]:text-sky-400'
const BIG = 'text-lg leading-6 font-bold text-zinc-900 dark:text-slate-50'
const SMALL = 'truncate text-[0.7rem] text-zinc-500 dark:text-slate-400'

/** Nuits consécutives sans défaut, en partant de la plus récente. */
function quietNights(nights: BackupNight[]): number {
  let count = 0
  for (let i = nights.length - 1; i >= 0 && nights[i]!.problems.length === 0; i--) count++
  return count
}

function entryLine(e: BackupEntry): string {
  const age = e.ageHours === null ? null : formatDuration(e.ageHours * 3600)
  if (e.state === 'ok') return `✓ ${e.label} : ${age ?? 'à jour'}`
  const state = STATES[e.state] ?? e.state

  return `✗ ${e.label} : ${state}${e.state === 'late' && age ? `, ${age}` : ''}${e.reason ? ` — ${e.reason}` : ''}`
}

export const BackupsCard: CardComponent<{ title: string }, BackupsData> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon="sh-restic" name="Sauvegardes" />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? card.props.title}</h3>
        <span className="flex-1" />
        {data &&
          (data.stale ? (
            <span role="alert" className="shrink-0 text-xs font-medium text-amber-700 dark:text-amber-300" title="Le relevé affiché est ancien : la sentinelle ne passe plus.">
              relevé {formatRelative(data.checkedAt, new Date(), 'short')} · sentinelle en retard
            </span>
          ) : (
            <span className="shrink-0 text-xs text-zinc-500 dark:text-slate-400" title={new Date(data.checkedAt).toLocaleString('fr-FR')}>
              relevé {formatRelative(data.checkedAt, new Date(), 'short')}
            </span>
          ))}
      </header>

      {data === null ? (
        <div aria-busy="true" className="grid h-16 grid-cols-2 gap-3">
          <div className="animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
          <div className="animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-3">
          <Summary data={data} />
          <LastRun run={data.restic} />
        </div>
      )}
    </div>
  )
}

function Summary({ data }: { data: BackupsData }) {
  const problems = data.entries.filter((e) => e.state !== 'ok')
  const quiet = quietNights(data.nights)

  return (
    <StatTile
      label="Sauvegardes"
      title={data.entries.map(entryLine).join('\n')}
      icon={problems.length === 0 ? <CheckIcon className="size-4" /> : <AlertIcon className="size-4" />}
      tone={problems.length === 0 ? GREEN : RED}
    >
      {problems.length === 0 ? (
        <>
          <p className={BIG}>
            {data.ok}/{data.total} à jour
          </p>
          <p className={SMALL}>{quiet > 0 ? `${quiet} ${quiet > 1 ? 'nuits' : 'nuit'} sans incident` : 'chaque nuit vers 3 h'}</p>
        </>
      ) : (
        <>
          <p className={`${BIG} text-red-700! dark:text-red-300!`}>{problems.length} à surveiller</p>
          <p className="truncate text-[0.7rem] font-medium text-red-700 dark:text-red-300">{problems.map((e) => e.label).join(', ')}</p>
        </>
      )}
    </StatTile>
  )
}

/** Étape en cause quand restic a échoué : la première qui a renvoyé une erreur. */
function failedStep(run: ResticRun): string {
  if (run.copyRc === null) return `sauvegarde locale (code ${run.rc})`
  if (run.copyRc !== 0) return `copie vers B2 (code ${run.copyRc})`

  return `nettoyage des dépôts (code ${run.rc})`
}

function LastRun({ run }: { run: ResticRun | null }) {
  if (run === null) {
    return (
      <StatTile label="Dernière sauvegarde" title="La sentinelle ne joint pas encore le statut de restic." icon={<ClockIcon className="size-4" />} tone={SKY}>
        <p className={BIG}>—</p>
        <p className={SMALL}>durée inconnue</p>
      </StatTile>
    )
  }

  const details = [
    `Terminée le ${formatDateTime(run.finishedAt)}, en ${formatDuration(run.durationSeconds)}`,
    run.backupSeconds !== null && `Sauvegarde locale : ${formatDuration(run.backupSeconds)}`,
    run.filesNew !== null && `Fichiers : ${formatNumber(run.filesNew)} nouveaux, ${formatNumber(run.filesChanged ?? 0)} modifiés`,
    run.bytesProcessed !== null && `Analysé : ${formatBytes(run.bytesProcessed)} (${formatNumber(run.filesProcessed ?? 0)} fichiers)`,
    run.dataAdded !== null && `Ajouté au dépôt : ${formatBytes(run.dataAdded)}, puis copié vers B2`,
    run.copySeconds !== null && `Copie vers B2 : ${formatDuration(run.copySeconds)}`,
  ]
    .filter(Boolean)
    .join('\n')

  return (
    <StatTile label="Dernière sauvegarde" title={details} icon={<ClockIcon className="size-4" />} tone={run.rc === 0 ? SKY : RED}>
      {run.rc === 0 ? (
        <>
          <p className={BIG}>{formatDuration(run.durationSeconds)}</p>
          <p className={SMALL}>
            {run.dataAdded !== null && `+${formatBytes(run.dataAdded)}`}
            {run.copySeconds !== null && ` · B2 en ${formatDuration(run.copySeconds)}`}
          </p>
        </>
      ) : (
        <>
          <p className={`${BIG} text-red-700! dark:text-red-300!`}>Échec</p>
          <p className="truncate text-[0.7rem] font-medium text-red-700 dark:text-red-300">{failedStep(run)}</p>
        </>
      )}
    </StatTile>
  )
}
