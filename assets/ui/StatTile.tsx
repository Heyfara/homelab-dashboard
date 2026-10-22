import type { ReactNode } from 'react'

/**
 * Tuile de chiffre clé (cartes backblaze, backups) : pastille d'icône, libellé,
 * puis le contenu (valeur en gros, une ligne de détail). `tone` colore le fond
 * et la pastille, en classes Tailwind écrites en entier :
 * "bg-sky-500/8 [&_[data-chip]]:bg-sky-500/15 [&_[data-chip]]:text-sky-600 …".
 * `title` : détail au survol.
 */
export function StatTile({ label, title, icon, tone, children }: { label: string; title: string; icon: ReactNode; tone: string; children: ReactNode }) {
  return (
    <div role="group" aria-label={label} title={title} className={`flex gap-2.5 rounded-xl px-2.5 py-2 tabular-nums ${tone}`}>
      <span data-chip className="grid size-8 shrink-0 place-items-center rounded-lg">
        {icon}
      </span>
      <div className="min-w-0">
        <p className="text-xs leading-4 text-zinc-500 dark:text-slate-400">{label}</p>
        {children}
      </div>
    </div>
  )
}
