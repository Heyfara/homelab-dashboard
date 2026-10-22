import { useState } from 'react'

const SELFHST = 'https://cdn.jsdelivr.net/gh/selfhst/icons'

/**
 * Icône d'un service, même convention que Homepage :
 * - « sh-<nom> » : icône https://selfh.st/icons en WebP, le seul format fourni
 *   pour toutes les icônes ; « sh-<nom>.svg » ou « .png » pour forcer le format,
 *   « sh-<nom>-light.svg » / « -dark.svg » pour les variantes quand elles existent ;
 * - une URL : l'image telle quelle ;
 * - sinon (ou si l'image ne charge pas) : l'initiale du nom.
 */
export function iconUrl(icon: string | null): string | null {
  if (!icon) return null
  if (icon.startsWith('sh-')) {
    const [, name, ext = 'webp'] = icon.match(/^sh-(.+?)(?:\.(svg|png|webp))?$/) ?? []
    return `${SELFHST}/${ext}/${name}.${ext}`
  }
  if (/^https?:\/\//.test(icon)) return icon
  return null
}

export function ServiceIcon({ icon, name }: { icon: string | null; name: string }) {
  const src = iconUrl(icon)
  // Icône introuvable (nom faux dans le YAML, CDN injoignable) : initiale.
  const [failed, setFailed] = useState<string | null>(null)

  return src && failed !== src ? (
    <img src={src} alt="" className="size-10 shrink-0 object-contain" loading="lazy" onError={() => setFailed(src)} />
  ) : (
    <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-zinc-100 text-lg font-semibold text-zinc-600 dark:bg-slate-800 dark:text-slate-300" aria-hidden="true">
      {name.charAt(0).toUpperCase()}
    </span>
  )
}
