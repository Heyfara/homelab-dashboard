import { useEffect, useRef, useState } from 'react'
import { fetchCard } from '../http/client'
import { snapshots } from '../http/snapshots'
import type { CardResult } from '../http/types'

export type CardDataState = {
  /** Dernier résultat reçu (ou gardé dans le navigateur), conservé à l'écran pendant un rechargement ou une erreur. */
  result: CardResult | null
  /** Vrai tant qu'aucun résultat n'est disponible. */
  loading: boolean
  /** Échec de la dernière requête (réseau, API), distinct d'un CardResult en erreur. */
  error: string | null
}

type Fetcher = (id: string, signal: AbortSignal) => Promise<CardResult>

type Options = {
  /** Faux quand la carte est loin de l'écran : pas de requête, reprise à son retour. */
  active?: boolean
  /** Clé des dernières données gardées dans le navigateur (défaut : id ; un flux y ajoute `?feed=…`). */
  snapshotKey?: string
}

/** Premier nouvel essai après un échec réseau, puis doublé à chaque échec, sans dépasser `refresh`. */
const RETRY_SECONDS = 5
/** Valeur périmée que le serveur relit : redemandée après ce délai, puis doublé. */
const STALE_RETRY_SECONDS = 3
/** Au-delà, la requête est abandonnée (connexion mobile qui ne répond plus). */
const TIMEOUT_MS = 20_000

/**
 * Données vivantes d'une carte : une requête au montage, puis une `refresh`
 * secondes après la fin de la précédente (jamais deux en parallèle).
 *
 * Pensé pour une connexion mobile médiocre : les dernières données gardées
 * s'affichent tout de suite ; une requête trop longue est abandonnée ; après un
 * échec, nouvel essai rapide (5 s, 10 s, 20 s…) et immédiat au retour du réseau.
 * En pause quand l'onglet est caché ou la carte hors de l'écran ; à la reprise,
 * rechargée seulement si son tour est passé. Annulée au démontage.
 */
export function useCardData(id: string, refreshSeconds: number, fetcher: Fetcher = fetchCard, { active = true, snapshotKey = id }: Options = {}): CardDataState {
  const [state, setState] = useState<CardDataState>(() => {
    const kept = snapshots.card(snapshotKey)
    return { result: kept, loading: kept === null, error: null }
  })
  const activeRef = useRef(active)
  const wakeRef = useRef<() => void>(() => {})

  useEffect(() => {
    activeRef.current = active
    wakeRef.current()
  }, [active])

  useEffect(() => {
    const controller = new AbortController()
    let timer: ReturnType<typeof setTimeout> | undefined
    let running = false
    /** Moment (ms) où la prochaine requête est due. */
    let nextAt = 0
    let failures = 0
    let staleRetries = 0

    const paused = () => document.hidden || !activeRef.current

    const schedule = (seconds: number) => {
      nextAt = Date.now() + seconds * 1000
      if (!paused()) timer = setTimeout(load, seconds * 1000)
    }

    const load = async () => {
      clearTimeout(timer)
      if (running || paused()) return
      running = true
      const request = new AbortController()
      const abort = () => request.abort()
      controller.signal.addEventListener('abort', abort)
      let timedOut = false
      const timeout = setTimeout(() => {
        timedOut = true
        request.abort()
      }, TIMEOUT_MS)
      let delay = refreshSeconds

      try {
        const result = await fetcher(id, request.signal)
        failures = 0
        snapshots.saveCard(snapshotKey, result)
        setState({ result, loading: false, error: null })
        if (result.status === 'ok' && result.stale) {
          delay = Math.min(refreshSeconds, STALE_RETRY_SECONDS * 2 ** staleRetries++)
        } else {
          staleRetries = 0
        }
      } catch (e) {
        if (controller.signal.aborted) return
        const message = timedOut ? `serveur trop lent, abandon après ${TIMEOUT_MS / 1000} s` : e instanceof Error ? e.message : String(e)
        setState((s) => ({ ...s, loading: false, error: message }))
        delay = Math.min(refreshSeconds, RETRY_SECONDS * 2 ** failures++)
      } finally {
        clearTimeout(timeout)
        controller.signal.removeEventListener('abort', abort)
        running = false
      }
      if (!controller.signal.aborted) schedule(delay)
    }

    /** Reprise (onglet visible, carte à l'écran) ou pause : recharge si son tour est passé. */
    const wake = () => {
      clearTimeout(timer)
      if (paused() || running) return
      const wait = nextAt - Date.now()
      if (wait <= 0) void load()
      else timer = setTimeout(load, wait)
    }

    const onOnline = () => {
      failures = 0
      void load()
    }

    wakeRef.current = wake
    document.addEventListener('visibilitychange', wake)
    window.addEventListener('online', onOnline)
    void load()

    return () => {
      controller.abort()
      clearTimeout(timer)
      wakeRef.current = () => {}
      document.removeEventListener('visibilitychange', wake)
      window.removeEventListener('online', onOnline)
    }
  }, [id, refreshSeconds, fetcher, snapshotKey])

  return state
}
