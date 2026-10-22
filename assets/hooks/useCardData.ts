import { useEffect, useState } from 'react'
import { fetchCard } from '../http/client'
import type { CardResult } from '../http/types'

export type CardDataState = {
  /** Dernier résultat reçu, gardé à l'écran pendant un rechargement ou une erreur. */
  result: CardResult | null
  /** Vrai tant qu'aucun résultat n'est arrivé. */
  loading: boolean
  /** Échec de la dernière requête (réseau, API), distinct d'un CardResult en erreur. */
  error: string | null
}

type Fetcher = (id: string, signal: AbortSignal) => Promise<CardResult>

/**
 * Données vivantes d'une carte : une requête au montage, puis une `refresh`
 * secondes après la fin de la précédente (jamais deux en parallèle). En pause
 * quand l'onglet est caché, rechargée à son retour, annulée au démontage.
 */
export function useCardData(id: string, refreshSeconds: number, fetcher: Fetcher = fetchCard): CardDataState {
  const [state, setState] = useState<CardDataState>({ result: null, loading: true, error: null })

  useEffect(() => {
    const controller = new AbortController()
    let timer: ReturnType<typeof setTimeout> | undefined
    let running = false

    const load = async () => {
      clearTimeout(timer)
      if (running || document.hidden) return
      running = true
      try {
        const result = await fetcher(id, controller.signal)
        setState({ result, loading: false, error: null })
      } catch (e) {
        if (controller.signal.aborted) return
        setState((s) => ({ ...s, loading: false, error: e instanceof Error ? e.message : String(e) }))
      } finally {
        running = false
      }
      if (!controller.signal.aborted && !document.hidden) {
        timer = setTimeout(load, refreshSeconds * 1000)
      }
    }

    const onVisibility = () => (document.hidden ? clearTimeout(timer) : void load())

    document.addEventListener('visibilitychange', onVisibility)
    void load()

    return () => {
      controller.abort()
      clearTimeout(timer)
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [id, refreshSeconds, fetcher])

  return state
}
