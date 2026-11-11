import type { MouseEvent } from 'react'
import type { TabView } from '../http/types'
import { GridIcon, HomeIcon, ShieldIcon } from '../ui/icons'

/** Adresse d'un onglet : le premier à celle du profil. */
export const tabPath = (profile: string, tabs: TabView[], slug: string) =>
  slug === tabs[0]?.slug ? `/${profile}` : `/${profile}/${slug}`

function TabIcon({ slug, first }: { slug: string; first: boolean }) {
  const className = 'size-6 sm:hidden'
  if (first) return <HomeIcon className={className} />
  if (slug === 'admin') return <ShieldIcon className={className} />
  return <GridIcon className={className} />
}

/**
 * Onglets du profil. Sur ordinateur, une rangée sous l'en-tête ; sur téléphone,
 * une barre fixe en bas (icône + nom, cibles de 56 px). Ce sont de vrais liens :
 * Ctrl+clic ou appui long ouvrent l'onglet à part.
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
      className="fixed inset-x-0 bottom-0 z-20 flex justify-around border-t border-zinc-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur sm:static sm:z-auto sm:justify-start sm:gap-1 sm:border-t-0 sm:border-b sm:bg-transparent sm:pb-2 sm:backdrop-blur-none dark:border-slate-800 dark:bg-slate-950/95 sm:dark:bg-transparent"
    >
      {tabs.map((tab, i) => {
        const current = tab.slug === active
        return (
          <a
            key={tab.slug}
            href={tabPath(profile, tabs, tab.slug)}
            onClick={go(tab.slug)}
            aria-current={current ? 'page' : undefined}
            className={`flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 text-xs focus-visible:outline-2 focus-visible:outline-indigo-500 sm:min-h-0 sm:flex-none sm:flex-row sm:rounded-lg sm:px-3 sm:py-1.5 sm:text-sm ${
              current
                ? 'font-semibold text-indigo-600 sm:bg-white sm:text-zinc-900 sm:shadow-sm sm:ring-1 sm:ring-zinc-200 dark:text-indigo-400 sm:dark:bg-slate-900 sm:dark:text-slate-50 sm:dark:ring-slate-800'
                : 'text-zinc-600 hover:text-zinc-900 sm:hover:bg-zinc-200 dark:text-slate-400 dark:hover:text-slate-100 sm:dark:hover:bg-slate-800'
            }`}
          >
            <TabIcon slug={tab.slug} first={i === 0} />
            {tab.title}
          </a>
        )
      })}
    </nav>
  )
}
