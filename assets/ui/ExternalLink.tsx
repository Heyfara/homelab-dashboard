import type { AnchorHTMLAttributes } from 'react'

/**
 * Lien vers un service ou un site : s'ouvre dans un nouvel onglet, le dashboard
 * reste ouvert. `noopener` empêche la page ouverte d'agir sur le dashboard.
 * Toutes les cartes l'utilisent (vérifié par ExternalLink.test.tsx).
 */
export function ExternalLink(props: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) {
  return <a {...props} target="_blank" rel="noopener noreferrer" />
}
