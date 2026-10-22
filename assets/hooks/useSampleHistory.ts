import { useEffect, useState } from 'react'
import { appendSample, type Sample } from '../ui/history'

/**
 * Historique des dernières mesures d'une carte, gardé dans le navigateur : la
 * source ne fournit que la valeur actuelle. Repart de zéro au rechargement.
 */
export function useSampleHistory<T>(at: string | null, value: T | null, max: number): T[] {
  const [history, setHistory] = useState<Sample<T>[]>([])

  useEffect(() => {
    if (at !== null && value !== null) setHistory((h) => appendSample(h, { at, value }, max))
    // eslint-disable-next-line react-hooks/exhaustive-deps -- une mesure = un `at`
  }, [at])

  return history.map((s) => s.value)
}
