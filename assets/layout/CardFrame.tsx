import type { ReactNode } from 'react'

/**
 * Le cadre commun à toutes les cartes. Sa hauteur est imposée par la grille :
 * le contenu ne déborde pas, et l'erreur tient sur une ligne (texte complet au survol).
 */
export function CardFrame({ children, error }: { children: ReactNode; error?: string | null }) {
  return (
    <article className="flex h-full flex-col gap-3 overflow-hidden rounded-2xl bg-white p-4 shadow-sm ring-1 ring-zinc-200 dark:bg-slate-900 dark:ring-slate-800">
      <div className="min-h-0 flex-1">{children}</div>
      {error && (
        <p title={error} className="truncate rounded-lg bg-rose-50 px-2.5 py-1 text-xs text-rose-800 dark:bg-rose-950 dark:text-rose-300">
          {error}
        </p>
      )}
    </article>
  )
}
