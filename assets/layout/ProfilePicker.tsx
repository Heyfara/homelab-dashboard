import { fetchProfiles } from '../http/client'
import { useJson } from '../hooks/useJson'
import { profileBadge } from '../ui/profileColors'
import { ErrorMessage } from './ErrorMessage'

/** Écran d'accueil : un bouton par membre de la famille, sans mot de passe. */
export function ProfilePicker({ onPick }: { onPick: (slug: string) => void }) {
  const { data: profiles, error } = useJson(fetchProfiles, 'profiles')

  return (
    <main className="mx-auto flex min-h-dvh max-w-3xl flex-col justify-center gap-8 px-4 py-12">
      <h1 className="text-center text-2xl font-semibold text-zinc-900 dark:text-slate-50">Qui est là ?</h1>
      {error && <ErrorMessage error={error} />}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
        {profiles?.map((p) => (
          <button
            key={p.slug}
            type="button"
            onClick={() => onPick(p.slug)}
            className="flex flex-col items-center gap-3 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-zinc-200 hover:ring-zinc-400 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:bg-slate-900 dark:ring-slate-800 dark:hover:ring-slate-600"
          >
            <span className={`grid size-16 place-items-center rounded-full text-2xl font-semibold text-white ${profileBadge[p.color]}`} aria-hidden="true">
              {p.name.charAt(0).toUpperCase()}
            </span>
            <span className="font-medium text-zinc-900 dark:text-slate-50">{p.name}</span>
          </button>
        ))}
      </div>
    </main>
  )
}
