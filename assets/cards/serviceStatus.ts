import type { Tone } from '../ui/StatusPill'

/** État d'un container d'après Beszel, ou erreur de la source. */
export type ContainerData =
  | {
      status: 'running' | 'restarting' | 'paused' | 'stopped'
      health: 'starting' | 'healthy' | 'unhealthy' | null
      /** Texte de Docker, en anglais : « Up 7 days » */
      uptime: string | null
      cpu: number | null
      memory: number | null
    }
  | { error: string }
export type ProbeData = { up: boolean; statusCode: number | null; latencyMs: number; error: string | null }

/** Données de ServiceCard::fetch() côté PHP. */
export type ServiceData = { container: ContainerData | null; probe: ProbeData | null }

/** Résume l'état d'un service en un libellé lisible par toute la famille. */
export function serviceStatus(data: ServiceData | null): { tone: Tone; label: string } {
  if (!data) return { tone: 'unknown', label: 'Vérification…' }

  const container = data.container && 'status' in data.container ? data.container : null

  if (container) {
    if (container.status === 'restarting') return { tone: 'warn', label: 'Redémarre' }
    if (container.status === 'paused') return { tone: 'warn', label: 'En pause' }
    if (container.status !== 'running') return { tone: 'down', label: 'Arrêté' }
    if (container.health === 'unhealthy') return { tone: 'down', label: 'En difficulté' }
    if (container.health === 'starting') return { tone: 'warn', label: 'Démarre' }
  }
  if (data.probe && !data.probe.up) return { tone: 'down', label: 'Ne répond pas' }
  if (container || data.probe) return { tone: 'ok', label: 'En ligne' }

  return { tone: 'unknown', label: 'Non surveillé' }
}
