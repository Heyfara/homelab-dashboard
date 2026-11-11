import type { MouseEvent } from 'react'
import type { TabView } from '../http/types'
import { GridIcon, HomeIcon, ShieldIcon } from '../ui/icons'

/** Adresse d'un onglet : le premier à celle du profil. */
export const tabPath = (profile: string, tabs: TabView[], slug: string) =>
  slug === tabs[0]?.slug ? `/${profile}` : `/${profile}/${slug}`

function TabIcon({ slug, first }: { slug: string; first: boolean }) {
  const className = 'size-6 shrink-0 lg:size-5'
  if (first) return <HomeIcon className={className} />
  if (slug === 'admin') return <ShieldIcon className={className} />
  return <GridIcon className={className} />
}

/**
 * Onglets du profil, en trois tailles :
 * - téléphone : barre fixe en bas (icône + nom, cibles de 56 px) ;
 * - de sm à lg : barre latérale étroite, icônes seules (nom au survol) ;
 * - lg et plus : barre latérale avec icône et nom.
 * La barre latérale reste visible au défilement (sticky). Ce sont de vrais
 * liens : Ctrl+clic ou appui long ouvrent l'onglet à part.
 */
export function TabBar({ profile, tabs, active, onChange }: { profile: string; tabs: TabView[]; active: string; onChange: (slug: string) => void }) {
  const go = (slug: string) => (event: MouseEvent) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
    event.preventDefault()
    onChange(slug)
  }

  return (
    <nav
      aria-label="Onglets"
      className="fixed inset-x-0 bottom-0 z-20 flex justify-around border-t border-zinc-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur sm:sticky sm:top-0 sm:h-screen sm:w-16 sm:shrink-0 sm:flex-col sm:justify-start sm:gap-1 sm:border-t-0 sm:border-r sm:bg-white sm:px-2 sm:py-6 sm:backdrop-blur-none lg:w-56 lg:px-3 dark:border-slate-800 dark:bg-slate-950/95 sm:dark:bg-slate-900"
    >
      {tabs.map((tab, i) => {
        const current = tab.slug === active
        return (
          <a
            key={tab.slug}
            href={tabPath(profile, tabs, tab.slug)}
            onClick={go(tab.slug)}
            aria-current={current ? 'page' : undefined}
            title={tab.title}
            className={`flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 text-xs focus-visible:outline-2 focus-visible:outline-indigo-500 sm:size-12 sm:min-h-0 sm:flex-none sm:rounded-xl lg:h-10 lg:w-full lg:flex-row lg:justify-start lg:gap-3 lg:px-3 lg:text-sm ${
              current
                ? 'font-semibold text-indigo-600 sm:bg-indigo-50 sm:text-indigo-700 dark:text-indigo-400 sm:dark:bg-indigo-950 sm:dark:text-indigo-300'
                : 'text-zinc-600 hover:text-zinc-900 sm:hover:bg-zinc-100 dark:text-slate-400 dark:hover:text-slate-100 sm:dark:hover:bg-slate-800'
            }`}
          >
            <TabIcon slug={tab.slug} first={i === 0} />
            <span className="sm:sr-only lg:not-sr-only">{tab.title}</span>
          </a>
        )
      })}
    </nav>
  )
}
