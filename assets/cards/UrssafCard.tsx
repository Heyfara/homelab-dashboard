import { useEffect, useState, type FormEvent } from 'react'
import { createPortal } from 'react-dom'
import type { CardComponent } from './registry'
import { ApiError, runCardAction } from '../http/client'
import { formatDateTime, formatDay } from '../ui/format'
import { ExternalLink } from '../ui/ExternalLink'
import { CloseIcon } from '../ui/icons'
import { StatusPill, type Tone } from '../ui/StatusPill'

/** Props de UrssafCard::describe() côté PHP. */
export type UrssafProps = { title: string; taskUrl: string; test: boolean }

/** Dernier passage de la tâche Dagu (UrssafCard::fetch()). */
export type UrssafRun = {
  id: string
  /** ok, partial, failed, running, queued ou unknown */
  state: string
  startedAt: string
  finishedAt: string | null
  amount: number | null
  test: boolean
  /** Dernière étape écrite par le navigateur : démarrage, connexion, navigation, rempli, formulaire-introuvable, envoyée, erreur */
  step: string | null
  message: string | null
  /** Écran noVNC de la session, tant qu'elle tourne */
  screenUrl: string | null
}

export type UrssafDeadline = { period: string; opens: string; due: string; open: boolean }

export type UrssafData = { run: UrssafRun | null; deadline: UrssafDeadline | null }

const STEPS: Record<string, { tone: Tone; label: string }> = {
  rempli: { tone: 'warn', label: 'à vérifier et valider' },
  'formulaire-introuvable': { tone: 'warn', label: 'formulaire indisponible' },
  envoyée: { tone: 'ok', label: 'déclaration envoyée' },
  erreur: { tone: 'down', label: 'erreur' },
}

/** État affiché de la dernière session ; `active` = l'écran est à montrer. */
export function sessionView(run: UrssafRun | null): { active: boolean; tone: Tone; label: string } {
  if (run === null) return { active: false, tone: 'unknown', label: 'jamais lancée' }
  if (run.state === 'running' || run.state === 'queued') {
    return { active: true, ...(STEPS[run.step ?? ''] ?? { tone: 'unknown', label: 'préparation…' }) }
  }
  if (run.state === 'ok') {
    return { active: false, tone: 'ok', label: run.step === 'envoyée' ? 'déclaration envoyée' : run.test ? 'test réussi' : 'terminée' }
  }

  return { active: false, tone: 'down', label: 'non envoyée' }
}

const AMOUNT_LABEL = 'Chiffre d’affaires (prestations de services)'
const input =
  'w-36 rounded-lg bg-zinc-50 px-3 py-1.5 text-right text-sm tabular-nums text-zinc-900 ring-1 ring-zinc-300 focus:outline-2 focus:outline-indigo-500 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700'
const primary = 'rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50'
const secondary =
  'rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-700 ring-1 ring-zinc-300 hover:bg-zinc-100 disabled:opacity-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800'

export const UrssafCard: CardComponent<UrssafProps, UrssafData> = ({ card, result, reload }) => {
  const data = result?.status === 'ok' ? result.data : null
  const run = data?.run ?? null
  const view = sessionView(run)
  const [amount, setAmount] = useState('0')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  /** Écran en grand, par-dessus le dashboard ; le fermer ne touche pas à la session. */
  const [screenOpen, setScreenOpen] = useState(false)
  const valid = /^\d{1,7}$/.test(amount.trim())

  useEffect(() => {
    if (!screenOpen) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setScreenOpen(false)
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [screenOpen])

  const act = async (action: 'start' | 'stop', body: Record<string, unknown> = {}) => {
    setBusy(true)
    setError(null)
    try {
      await runCardAction(card.id, action, body)
      if (action === 'start') setScreenOpen(true)
      reload?.()
    } catch (e) {
      setError(e instanceof ApiError || e instanceof Error ? e.message : String(e))
    } finally {
      setBusy(false)
    }
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (valid) void act('start', { amount: Number(amount.trim()) })
  }

  return (
    <div className="flex h-full flex-col gap-2">
      <header className="flex flex-wrap items-center gap-2">
        <h2 className="text-sm font-semibold text-zinc-900 dark:text-slate-100">{card.props.title}</h2>
        {card.props.test && <StatusPill tone="warn" label="mode test" />}
        <ExternalLink href={card.props.taskUrl} className="ml-auto text-xs text-zinc-500 hover:underline dark:text-slate-400">
          Historique
        </ExternalLink>
      </header>

      {data?.deadline && (
        <p className="text-xs text-zinc-600 dark:text-slate-300">
          <span className="font-medium text-zinc-900 dark:text-slate-100">{data.deadline.period}</span>
          {data.deadline.open
            ? ` : déclaration ouverte jusqu’au ${formatDay(data.deadline.due)}`
            : ` : à déclarer du ${formatDay(data.deadline.opens)} au ${formatDay(data.deadline.due)}`}
        </p>
      )}

      {view.active && run ? (
        <div className="flex flex-col gap-2">
          <StatusPill tone={view.tone} label={view.label} />
          <div className="flex flex-wrap gap-2">
            <button type="button" onClick={() => setScreenOpen(true)} className={primary}>
              Afficher l’écran
            </button>
          </div>
        </div>
      ) : (
        <form onSubmit={submit} className="flex flex-col gap-2">
          <label className="flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-700 dark:text-slate-200">
            {AMOUNT_LABEL}
            <span className="flex items-center gap-1.5 text-sm">
              <input type="number" inputMode="numeric" min={0} step={1} value={amount} onChange={(e) => setAmount(e.target.value)} className={input} aria-label={AMOUNT_LABEL} />
              €
            </span>
          </label>
          <button type="submit" disabled={busy || !valid} className={`${primary} self-start`}>
            Préparer la déclaration
          </button>
          {run && run.finishedAt && (
            <p className="text-xs text-zinc-500 dark:text-slate-400">
              Dernière session le {formatDateTime(run.startedAt)}
              {run.amount !== null && ` (${run.amount} €)`} : {view.label}.
            </p>
          )}
        </form>
      )}

      {error && !screenOpen && (
        <p role="alert" className="text-xs text-rose-700 dark:text-rose-300">
          {error}
        </p>
      )}

      {screenOpen &&
        createPortal(
          <div className="fixed inset-0 z-40 flex items-center justify-center p-2 sm:p-6">
            <div className="absolute inset-0 bg-zinc-950/40 dark:bg-black/60" onClick={() => setScreenOpen(false)} aria-hidden="true" />
            <div
              role="dialog"
              aria-modal="true"
              aria-label={card.props.title}
              className="relative flex h-full w-full max-w-6xl flex-col gap-3 rounded-2xl bg-white p-4 shadow-xl dark:bg-slate-900"
            >
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-lg font-semibold text-zinc-900 dark:text-slate-50">{card.props.title}</h2>
                {card.props.test && <StatusPill tone="warn" label="mode test" />}
                {run && <StatusPill tone={view.tone} label={view.label} />}
                <button
                  type="button"
                  aria-label="Masquer l’écran"
                  title="Masquer l’écran (la session continue)"
                  onClick={() => setScreenOpen(false)}
                  className="ml-auto grid size-8 place-items-center rounded-lg text-zinc-500 hover:bg-zinc-200 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800"
                >
                  <CloseIcon className="size-5" />
                </button>
              </div>

              <p className="text-sm text-zinc-700 dark:text-slate-200">
                {view.active && run?.message
                  ? run.message
                  : view.active || !run
                    ? 'Préparation de la session…'
                    : `Session terminée : ${view.label}.`}
              </p>

              {view.active && run?.screenUrl ? (
                <iframe title="Écran du navigateur de la déclaration" src={run.screenUrl} className="min-h-0 w-full flex-1 rounded-lg bg-zinc-900 ring-1 ring-zinc-300 dark:ring-slate-700" />
              ) : (
                <div className="grid min-h-0 flex-1 place-items-center rounded-lg bg-zinc-100 text-sm text-zinc-500 dark:bg-slate-800 dark:text-slate-400">
                  {view.active || !run ? 'L’écran du navigateur apparaît dans quelques secondes…' : 'Plus d’écran : la session est fermée.'}
                </div>
              )}

              <div className="flex flex-wrap items-center gap-3">
                {view.active && (
                  <button type="button" onClick={() => void act('stop')} disabled={busy} className={secondary}>
                    Fermer la session
                  </button>
                )}
                {view.active && run?.screenUrl && (
                  <ExternalLink href={run.screenUrl} className="text-xs text-zinc-500 hover:underline dark:text-slate-400">
                    Ouvrir dans un onglet
                  </ExternalLink>
                )}
                <span className="text-xs text-zinc-500 dark:text-slate-400">
                  Vérifie le montant puis clique toi-même sur « Valider ». Fermeture automatique au bout de 15 min.
                </span>
                {error && (
                  <p role="alert" className="text-sm text-rose-700 dark:text-rose-300">
                    {error}
                  </p>
                )}
              </div>
            </div>
          </div>,
          document.body,
        )}
    </div>
  )
}
