import { useEffect, useState, type FormEvent } from 'react'
import { deleteCredential, fetchCredentials, fetchDashboard, saveCredential } from '../http/client'
import type { CredentialView } from '../http/types'
import { useJson } from '../hooks/useJson'
import { formatDateTime } from '../ui/format'
import { ErrorMessage } from './ErrorMessage'

/**
 * « Mes clés » d'un profil : les clés d'API que la personne colle elle-même
 * pour ses propres cartes (option credentials: profile). Les clés ne
 * reviennent jamais du serveur : on voit seulement si elles sont configurées.
 */
export function CredentialsPage({ slug, onBack }: { slug: string; onBack: () => void }) {
  const { data: dashboard } = useJson((signal) => fetchDashboard(slug, signal), slug)
  const { data, error } = useJson((signal) => fetchCredentials(slug, signal), slug)
  const [services, setServices] = useState<CredentialView[] | null>(null)

  useEffect(() => setServices(data), [data])
  useEffect(() => {
    document.title = `Mes clés · ${dashboard?.profile.name ?? 'Maison'}`
  }, [dashboard])

  const update = (id: string, changes: Partial<CredentialView>) => setServices((list) => list?.map((s) => (s.id === id ? { ...s, ...changes } : s)) ?? null)

  return (
    <main className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6">
      <header className="flex items-center justify-between gap-4">
        <h1 className="text-xl font-semibold text-zinc-900 dark:text-slate-50">Mes clés · {dashboard?.profile.name ?? slug}</h1>
        <button type="button" onClick={onBack} className="rounded-lg px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-200 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800">
          ← Retour au dashboard
        </button>
      </header>
      <p className="text-sm text-zinc-600 dark:text-slate-400">
        Les clés d'API de tes comptes, pour que tes cartes montrent tes propres informations. Elles sont vérifiées avant d'être enregistrées,
        gardées chiffrées sur le serveur et ne s'affichent plus jamais ensuite.
      </p>

      {error && <ErrorMessage error={error} />}
      {services?.map((s) => <Service key={s.id} slug={slug} service={s} onChange={(changes) => update(s.id, changes)} />)}
    </main>
  )
}

function Service({ slug, service, onChange }: { slug: string; service: CredentialView; onChange: (changes: Partial<CredentialView>) => void }) {
  const [key, setKey] = useState('')
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)

  const save = async (event: FormEvent) => {
    event.preventDefault()
    setBusy(true)
    setProblem(null)
    try {
      const saved = await saveCredential(slug, service.id, key)
      onChange(saved)
      setKey('')
    } catch (e) {
      setProblem(e instanceof Error ? e.message : String(e))
    } finally {
      setBusy(false)
    }
  }

  const remove = async () => {
    setBusy(true)
    setProblem(null)
    try {
      await deleteCredential(slug, service.id)
      onChange({ configured: false, account: null, updatedAt: null })
      setConfirming(false)
    } catch (e) {
      setProblem(e instanceof Error ? e.message : String(e))
    } finally {
      setBusy(false)
    }
  }

  const inputId = `cle-${service.id}`

  return (
    <section aria-label={service.label} className="flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-zinc-200 dark:bg-slate-900 dark:ring-slate-800">
      <div className="flex items-baseline gap-2">
        <h2 className="font-semibold text-zinc-900 dark:text-slate-50">{service.label}</h2>
        <span className="flex-1" />
        {service.configured ? (
          <span className="text-sm text-emerald-700 dark:text-emerald-400">
            Configurée · compte {service.account ?? '?'}
            {service.updatedAt && <span className="text-zinc-500 dark:text-slate-400"> · le {formatDateTime(service.updatedAt)}</span>}
          </span>
        ) : (
          <span className="text-sm text-zinc-500 dark:text-slate-400">Pas encore de clé.</span>
        )}
      </div>
      <p className="text-xs text-zinc-500 dark:text-slate-400">{service.help}</p>

      <form onSubmit={save} className="flex flex-wrap items-center gap-2">
        <label htmlFor={inputId} className="sr-only">
          Clé {service.label}
        </label>
        <input
          id={inputId}
          type="password"
          autoComplete="off"
          spellCheck={false}
          value={key}
          onChange={(e) => setKey(e.target.value)}
          placeholder={service.configured ? 'Coller une nouvelle clé pour la remplacer' : 'Coller la clé ici'}
          className="min-w-0 flex-1 rounded-lg bg-zinc-50 px-3 py-1.5 font-mono text-sm text-zinc-900 ring-1 ring-zinc-300 focus:outline-2 focus:outline-indigo-500 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700"
        />
        <button type="submit" disabled={busy || key.trim() === ''} className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
          {busy ? 'Vérification…' : service.configured ? 'Remplacer' : 'Enregistrer'}
        </button>
        {service.configured &&
          (confirming ? (
            <button type="button" onClick={remove} disabled={busy} className="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-500">
              Confirmer la suppression
            </button>
          ) : (
            <button type="button" onClick={() => setConfirming(true)} className="rounded-lg px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950">
              Supprimer
            </button>
          ))}
      </form>

      {problem && (
        <p role="alert" className="rounded-lg bg-rose-50 px-3 py-1.5 text-sm text-rose-800 dark:bg-rose-950 dark:text-rose-300">
          {problem}
        </p>
      )}
    </section>
  )
}
