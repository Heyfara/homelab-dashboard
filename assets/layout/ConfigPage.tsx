import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { deleteCredential, fetchConfig, fetchDashboard, resetWeatherLocation, saveCredential, saveWeatherLocation, searchPlaces } from '../http/client'
import type { CredentialView, PlaceView, WeatherLocationView } from '../http/types'
import { useJson } from '../hooks/useJson'
import { formatDateTime } from '../ui/format'
import { ErrorMessage } from './ErrorMessage'

/**
 * « Ma config » d'un profil : une section par carte que la personne règle
 * elle-même. Clés d'API (option credentials: profile) : elles ne reviennent
 * jamais du serveur, on voit seulement si elles sont configurées. Lieu de la
 * météo (option location: profile) : recherche de ville ou coordonnées.
 */
export function ConfigPage({ slug, onBack }: { slug: string; onBack: () => void }) {
  const { data: dashboard } = useJson((signal) => fetchDashboard(slug, signal), slug)
  const { data, error } = useJson((signal) => fetchConfig(slug, signal), slug)
  const [services, setServices] = useState<CredentialView[] | null>(null)

  useEffect(() => setServices(data?.credentials ?? null), [data])
  useEffect(() => {
    document.title = `Ma config · ${dashboard?.profile.name ?? 'Maison'}`
  }, [dashboard])

  const update = (id: string, changes: Partial<CredentialView>) => setServices((list) => list?.map((s) => (s.id === id ? { ...s, ...changes } : s)) ?? null)

  return (
    <main className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6">
      <header className="flex items-center justify-between gap-4">
        <h1 className="text-xl font-semibold text-zinc-900 dark:text-slate-50">Ma config · {dashboard?.profile.name ?? slug}</h1>
        <button type="button" onClick={onBack} className="rounded-lg px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-200 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800">
          ← Retour au dashboard
        </button>
      </header>
      <p className="text-sm text-zinc-600 dark:text-slate-400">Les réglages de tes cartes, pour qu'elles montrent tes propres informations.</p>

      {error && <ErrorMessage error={error} />}
      {data && services?.length === 0 && !data.weather && <p className="text-sm text-zinc-500 dark:text-slate-400">Aucune de tes cartes n’a de réglage personnel.</p>}
      {data?.weather && <Weather slug={slug} initial={data.weather} />}
      {services?.map((s) => <Service key={s.id} slug={slug} service={s} onChange={(changes) => update(s.id, changes)} />)}
    </main>
  )
}

const panel = 'flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-zinc-200 dark:bg-slate-900 dark:ring-slate-800'
const input =
  'min-w-0 rounded-lg bg-zinc-50 px-3 py-1.5 text-sm text-zinc-900 ring-1 ring-zinc-300 focus:outline-2 focus:outline-indigo-500 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700'
const primary = 'rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50'
const secondary = 'rounded-lg px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-100 disabled:opacity-50 dark:text-slate-300 dark:hover:bg-slate-800'

function Section({ label, status, children }: { label: string; status: ReactNode; children: ReactNode }) {
  return (
    <section aria-label={label} className={panel}>
      <div className="flex items-baseline gap-2">
        <h2 className="font-semibold text-zinc-900 dark:text-slate-50">{label}</h2>
        <span className="flex-1" />
        {status}
      </div>
      {children}
    </section>
  )
}

function Problem({ message }: { message: string | null }) {
  if (!message) return null
  return (
    <p role="alert" className="rounded-lg bg-rose-50 px-3 py-1.5 text-sm text-rose-800 dark:bg-rose-950 dark:text-rose-300">
      {message}
    </p>
  )
}

const errorText = (e: unknown) => (e instanceof Error ? e.message : String(e))

/** « 45,92 » ou « 45.92 » ; NaN si ce n'est pas un nombre (le serveur explique le refus). */
const parseCoordinate = (text: string) => (text.trim() === '' ? Number.NaN : Number(text.trim().replace(',', '.')))

const placeLabel = (p: PlaceView) => [p.name, [p.region, p.country].filter(Boolean).join(', ')].filter(Boolean).join(' · ')

/** Lieu de la carte météo : celui choisi par la personne, sinon celui du YAML. */
function Weather({ slug, initial }: { slug: string; initial: WeatherLocationView }) {
  const [location, setLocation] = useState(initial)
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<PlaceView[] | null>(null)
  const [manual, setManual] = useState({ place: '', latitude: '', longitude: '' })
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  const run = async (action: () => Promise<void>) => {
    setBusy(true)
    setProblem(null)
    try {
      await action()
    } catch (e) {
      setProblem(errorText(e))
    } finally {
      setBusy(false)
    }
  }

  const search = (event: FormEvent) => {
    event.preventDefault()
    void run(async () => {
      const found = await searchPlaces(query)
      setResults(found)
      if (found.length === 0) setProblem('Aucune ville trouvée sous ce nom.')
    })
  }

  const choose = (place: { place: string; latitude: number; longitude: number }) =>
    run(async () => {
      setLocation(await saveWeatherLocation(slug, place))
      setResults(null)
      setQuery('')
      setManual({ place: '', latitude: '', longitude: '' })
    })

  const saveManual = (event: FormEvent) => {
    event.preventDefault()
    void choose({ place: manual.place, latitude: parseCoordinate(manual.latitude), longitude: parseCoordinate(manual.longitude) })
  }

  const reset = () =>
    run(async () => {
      await resetWeatherLocation(slug)
      // Le lieu par défaut vient du YAML : on le relit.
      setLocation((await fetchConfig(slug)).weather ?? location)
    })

  const status = location.custom ? (
    <span className="text-sm text-emerald-700 dark:text-emerald-400">
      Lieu choisi : {location.place}
      {location.updatedAt && <span className="text-zinc-500 dark:text-slate-400"> · le {formatDateTime(location.updatedAt)}</span>}
    </span>
  ) : location.place || location.latitude !== null ? (
    <span className="text-sm text-zinc-500 dark:text-slate-400">Lieu par défaut : {location.place ?? `${location.latitude}, ${location.longitude}`}</span>
  ) : (
    <span className="text-sm text-zinc-500 dark:text-slate-400">Pas encore de lieu.</span>
  )

  return (
    <Section label="Météo" status={status}>
      <form onSubmit={search} className="flex flex-wrap items-center gap-2">
        <label htmlFor="meteo-ville" className="sr-only">
          Chercher une ville
        </label>
        <input id="meteo-ville" type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Chercher une ville" className={`${input} flex-1`} />
        <button type="submit" disabled={busy || query.trim().length < 2} className={primary}>
          Chercher
        </button>
        {location.custom && (
          <button type="button" onClick={reset} disabled={busy} className={secondary}>
            Revenir au lieu par défaut
          </button>
        )}
      </form>

      {results && results.length > 0 && (
        <ul className="flex flex-col gap-1">
          {results.map((p) => (
            <li key={`${p.latitude},${p.longitude}`}>
              <button
                type="button"
                onClick={() => choose({ place: p.name, latitude: p.latitude, longitude: p.longitude })}
                disabled={busy}
                className="w-full rounded-lg px-3 py-1.5 text-left text-sm text-zinc-800 ring-1 ring-zinc-200 hover:bg-indigo-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800"
              >
                {placeLabel(p)}
              </button>
            </li>
          ))}
        </ul>
      )}

      <details className="text-sm text-zinc-600 dark:text-slate-400">
        <summary className="cursor-pointer select-none">Ou saisir des coordonnées</summary>
        <form onSubmit={saveManual} className="mt-2 flex flex-wrap items-center gap-2">
          {(
            [
              ['place', 'Nom du lieu', 'Chalet'],
              ['latitude', 'Latitude', '45,92'],
              ['longitude', 'Longitude', '6,87'],
            ] as const
          ).map(([field, label, example]) => (
            <label key={field} className="flex flex-col gap-1">
              <span className="text-xs">{label}</span>
              <input
                type="text"
                inputMode={field === 'place' ? 'text' : 'decimal'}
                value={manual[field]}
                onChange={(e) => setManual((m) => ({ ...m, [field]: e.target.value }))}
                placeholder={example}
                className={`${input} ${field === 'place' ? 'w-40' : 'w-24'}`}
              />
            </label>
          ))}
          <button type="submit" disabled={busy || manual.place.trim() === ''} className={`${primary} self-end`}>
            Enregistrer ces coordonnées
          </button>
        </form>
      </details>

      <Problem message={problem} />
    </Section>
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
      setProblem(errorText(e))
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
      setProblem(errorText(e))
    } finally {
      setBusy(false)
    }
  }

  const inputId = `cle-${service.id}`

  return (
    <Section
      label={service.label}
      status={
        service.configured ? (
          <span className="text-sm text-emerald-700 dark:text-emerald-400">
            Configurée · compte {service.account ?? '?'}
            {service.updatedAt && <span className="text-zinc-500 dark:text-slate-400"> · le {formatDateTime(service.updatedAt)}</span>}
          </span>
        ) : (
          <span className="text-sm text-zinc-500 dark:text-slate-400">Pas encore de clé.</span>
        )
      }
    >
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
          className={`${input} flex-1 font-mono`}
        />
        <button type="submit" disabled={busy || key.trim() === ''} className={primary}>
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

      <Problem message={problem} />
    </Section>
  )
}
