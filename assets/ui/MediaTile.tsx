import type { ReactNode } from 'react'
import { ExternalLink } from './ExternalLink'

/**
 * Vignette cliquable d'un média : image recadrée, titre et sous-titre sur un
 * dégradé en bas, léger zoom au survol. `badge` s'affiche en haut à gauche.
 * `fit="contain"` : image entière sur fond blanc, pour les photos de produits
 * détourées (sets Lego) qu'un recadrage amputerait.
 */
export function MediaTile({ href, image, title, subtitle, badge, fit = 'cover' }: { href: string; image: string | null; title: string; subtitle?: string | null; badge?: ReactNode; fit?: 'cover' | 'contain' }) {
  const label = subtitle ? `${title} (${subtitle})` : title

  return (
    <ExternalLink
      href={href}
      aria-label={label}
      title={label}
      draggable={false}
      className={`group relative block h-full overflow-hidden rounded-lg ring-1 ${fit === 'contain' ? 'bg-white' : 'bg-zinc-200 dark:bg-slate-800'} ring-black/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500 dark:ring-white/10`}
    >
      {image ? <img src={image} alt="" loading="lazy" draggable={false} className={`size-full transition ${fit === 'contain' ? 'object-contain p-2 pb-10' : 'object-cover'} duration-300 group-hover:scale-105 motion-reduce:transition-none`} /> : null}
      {badge && <span className="absolute top-1.5 left-1.5">{badge}</span>}
      <span className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 via-black/50 to-transparent px-2 pt-6 pb-1.5 text-white">
        <span className="line-clamp-2 text-xs leading-tight font-semibold">{title}</span>
        {subtitle && <span className="mt-0.5 block truncate text-[0.65rem] text-white/75">{subtitle}</span>}
      </span>
    </ExternalLink>
  )
}
