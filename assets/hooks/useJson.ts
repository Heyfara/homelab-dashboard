import { useEffect, useState } from 'react'

export type JsonState<T> = { data: T | null; error: Error | null; loading: boolean }

/** Une requête au montage (et quand `key` change), annulée au démontage. */
export function useJson<T>(load: (signal: AbortSignal) => Promise<T>, key: string): JsonState<T> {
  const [state, setState] = useState<JsonState<T>>({ data: null, error: null, loading: true })

  useEffect(() => {
    const controller = new AbortController()
    setState({ data: null, error: null, loading: true })
    load(controller.signal).then(
      (data) => setState({ data, error: null, loading: false }),
      (error: unknown) => {
        if (!controller.signal.aborted) setState({ data: null, error: error instanceof Error ? error : new Error(String(error)), loading: false })
      },
    )
    return () => controller.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps -- `key` identifie la requête
  }, [key])

  return state
}
