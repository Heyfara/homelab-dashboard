import type { CardResult, DashboardView } from './types'

/**
 * Dernières données reçues, gardées dans le navigateur : à l'ouverture, la page
 * s'affiche tout de suite avec elles (connexion lente, serveur injoignable),
 * puis se met à jour. Le stockage peut être indisponible ou plein (navigation
 * privée, quota) : on s'en passe sans erreur.
 */
const CARD = 'dashboard.card.'
const DASHBOARD = 'dashboard.view.'

function read<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(key)
    return raw === null ? null : (JSON.parse(raw) as T)
  } catch {
    return null
  }
}

function write(key: string, value: unknown): void {
  try {
    localStorage.setItem(key, JSON.stringify(value))
  } catch {
    /* stockage indisponible ou plein */
  }
}

export const snapshots = {
  /** `key` : id de la carte, suivi de `?feed=…` pour un flux. */
  card: (key: string) => read<CardResult>(CARD + key),

  /** Seuls les résultats valides sont gardés : une erreur ne remplace pas les dernières données. */
  saveCard: (key: string, result: CardResult) => {
    if (result.status === 'ok') write(CARD + key, result)
  },

  dashboard: (slug: string) => read<DashboardView>(DASHBOARD + slug),

  saveDashboard: (slug: string, view: DashboardView) => write(DASHBOARD + slug, view),

  /**
   * Oublie les cartes du profil absentes de `ids` (une carte dont les options
   * changent change d'id). Les ids de carte commencent par `<profil>-`.
   */
  prune: (slug: string, ids: string[]) => {
    try {
      const keep = new Set(ids)
      const prefix = `${CARD}${slug}-`
      for (const key of Object.keys(localStorage)) {
        if (key.startsWith(prefix) && !keep.has(key.slice(CARD.length).split('?')[0]!)) localStorage.removeItem(key)
      }
    } catch {
      /* stockage indisponible */
    }
  },
}
