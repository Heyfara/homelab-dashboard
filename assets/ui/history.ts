export type Sample<T> = { at: string; value: T }

/**
 * Ajoute une mesure à un historique borné. Une mesure déjà vue (même `at` :
 * réponse servie depuis le cache du serveur) ne compte pas deux fois.
 */
export function appendSample<T>(history: Sample<T>[], sample: Sample<T>, max: number): Sample<T>[] {
  if (history.at(-1)?.at === sample.at) return history
  return [...history, sample].slice(-max)
}
