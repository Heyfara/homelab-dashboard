import type { ReactNode, Ref } from 'react'

/**
 * Le cadre commun à toutes les cartes. Sa hauteur est imposée par la grille :
 * le contenu ne déborde pas (relative : les éléments positionnés aussi), et l'erreur tient sur une ligne (texte complet au survol).
 * `notice` : remarque discrète sur une ligne (âge de données anciennes).
 */
export function CardFrame({ children, error, notice, ref }: { children: ReactNode; error?: string | null; notice?: string | null; ref?: Ref<HTMLElement> }) {
  return (
    <article ref={ref} className="relative flex h-full flex-col gap-3 overflow-hidden rounded-2xl bg-white p-4 shadow-sm ring-1 ring-zinc-200 dark:bg-slate-900 dark:ring-slate-800">
      <div className="min-h-0 flex-1">{children}</div>
      {error && (
        <p title={error} className="truncate rounded-lg bg-rose-50 px-2.5 py-1 text-xs text-rose-800 dark:bg-rose-950 dark:text-rose-300">
          {error}
        </p>
      )}
      {notice && !error && (
        <p title={notice} className="truncate rounded-lg bg-amber-50 px-2.5 py-1 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">
          {notice}
        </p>
      )}
    </article>
  )
}
