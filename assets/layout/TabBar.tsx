import { useState, type MouseEvent } from 'react'
import type { TabView } from '../http/types'
import { ChevronsLeftIcon, ChevronsRightIcon, GridIcon, HomeIcon, ShieldIcon } from '../ui/icons'

const COMPACT_KEY = 'dashboard.sidebar.compact'

// Préférence de l'appareil ; stockage indisponible (navigation privée) : barre dépliée.
const compactPref = {
  get: () => {
    try {
      return localStorage.getItem(COMPACT_KEY) === '1'
    } catch {
      return false
    }
  },
  set: (compact: boolean) => {
    try {
      if (compact) localStorage.setItem(COMPACT_KEY, '1')
      else localStorage.removeItem(COMPACT_KEY)
    } catch {
      /* rien */
    }
  },
}

/** Adresse d'un onglet : le premier à celle du profil. */
export const tabPath = (profile: string, tabs: TabView[], slug: string) =>
  slug === tabs[0]?.slug ? `/${profile}` : `/${profile}/${slug}`

function TabIcon({ slug, first, compact }: { slug: string; first: boolean; compact: boolean }) {
  const className = `size-6 shrink-0 ${compact ? '' : 'lg:size-5'}`
  if (first) return <HomeIcon className={className} />
  if (slug === 'admin') return <ShieldIcon className={className} />
  return <GridIcon className={className} />
}

/**
 * Onglets du profil, en trois tailles :
 * - téléphone : barre fixe en bas (icône + nom, cibles de 56 px) ;
 * - de sm à lg : barre latérale étroite, icônes seules (nom au survol) ;
 * - lg et plus : barre latérale avec icône et nom, ou icônes seules (mode
 *   compact, bouton en haut de la barre, retenu par l'appareil).
 * La barre latérale reste visible au défilement (sticky). Ce sont de vrais
 * liens : Ctrl+clic ou appui long ouvrent l'onglet à part.
 */
export function TabBar({ profile, tabs, active, onChange }: { profile: string; tabs: TabView[]; active: string; onChange: (slug: string) => void }) {
  const [compact, setCompact] = useState(compactPref.get)
  const toggle = () => {
    compactPref.set(!compact)
    setCompact(!compact)
  }
  const go = (slug: string) => (event: MouseEvent) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
    event.preventDefault()
    onChange(slug)
  }

  return (
    <nav
      aria-label="Onglets"
      className={`fixed inset-x-0 bottom-0 z-20 flex justify-around border-t border-zinc-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur sm:sticky sm:top-0 sm:h-screen sm:w-16 sm:shrink-0 sm:flex-col sm:justify-start sm:gap-1 sm:border-t-0 sm:border-r sm:bg-white sm:px-2 sm:py-6 sm:backdrop-blur-none ${compact ? '' : 'lg:w-56 lg:px-3'} dark:border-slate-800 dark:bg-slate-950/95 sm:dark:bg-slate-900`}
    >
      <div className={`hidden items-center lg:flex ${compact ? 'justify-center' : 'justify-between pl-3'}`}>
        {!compact && <span className="truncate text-lg font-semibold text-zinc-900 dark:text-slate-50">Maison</span>}
        <button
          type="button"
          onClick={toggle}
          aria-expanded={!compact}
          aria-label={compact ? 'Déplier la barre' : 'Réduire la barre'}
          title={compact ? 'Déplier la barre' : 'Réduire la barre'}
          className={`grid shrink-0 place-items-center rounded-xl text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100 ${compact ? 'size-12' : 'size-10'}`}
        >
          {compact ? <ChevronsRightIcon className="size-5" /> : <ChevronsLeftIcon className="size-5" />}
        </button>
      </div>
      <hr className="my-3 hidden border-zinc-200 lg:block dark:border-slate-800" />
      {tabs.map((tab, i) => {
        const current = tab.slug === active
        return (
          <a
            key={tab.slug}
            href={tabPath(profile, tabs, tab.slug)}
            onClick={go(tab.slug)}
            aria-current={current ? 'page' : undefined}
            title={tab.title}
            className={`flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 text-xs focus-visible:outline-2 focus-visible:outline-indigo-500 sm:size-12 sm:min-h-0 sm:flex-none sm:rounded-xl ${compact ? '' : 'lg:h-10 lg:w-full lg:flex-row lg:justify-start lg:gap-3 lg:px-3 lg:text-sm'} ${
              current
                ? 'font-semibold text-indigo-600 sm:bg-indigo-50 sm:text-indigo-700 dark:text-indigo-400 sm:dark:bg-indigo-950 sm:dark:text-indigo-300'
                : 'text-zinc-600 hover:text-zinc-900 sm:hover:bg-zinc-100 dark:text-slate-400 dark:hover:text-slate-100 sm:dark:hover:bg-slate-800'
            }`}
          >
            <TabIcon slug={tab.slug} first={i === 0} compact={compact} />
            <span className={`sm:sr-only ${compact ? '' : 'lg:not-sr-only'}`}>{tab.title}</span>
          </a>
        )
      })}
    </nav>
  )
}
