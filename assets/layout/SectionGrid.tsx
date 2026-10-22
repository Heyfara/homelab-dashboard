import type { CSSProperties, ReactNode } from 'react'

type Item = { key: string; span: number; rows: number }

/**
 * Grille d'une section : une colonne sur téléphone, `columns` à partir de md.
 * Toutes les rangées ont la même hauteur (--card-row, app.css, calée sur la
 * carte service) : une carte de `span` × `rows` reste un multiple exact des
 * petites cartes. Colonnes et tailles passent en variables CSS, Tailwind ne
 * pouvant pas générer de classe pour une valeur connue seulement à l'exécution.
 */
export function SectionGrid<T extends Item>({ columns, items, render }: { columns: number; items: T[]; render: (item: T) => ReactNode }) {
  return (
    <div
      className="grid auto-rows-(--card-row) grid-cols-1 gap-4 md:grid-cols-[repeat(var(--cols),minmax(0,1fr))]"
      style={{ '--cols': String(columns) } as CSSProperties}
    >
      {items.map((item) => (
        <div
          key={item.key}
          className="row-span-(--rows) min-h-0 md:col-span-(--span)"
          style={{ '--span': String(Math.min(item.span, columns)), '--rows': String(Math.max(1, item.rows)) } as CSSProperties}
        >
          {render(item)}
        </div>
      ))}
    </div>
  )
}
