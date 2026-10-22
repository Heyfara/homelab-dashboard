export type ParsedTitle = {
  /** Paquet (sans registre Docker) ou nom du groupe de mises à jour */
  name: string
  /** Version cible, « v2.10.7 » */
  version: string | null
  /** Icône selfh.st devinée d'après le paquet, ou null */
  icon: string | null
  /** Mise à jour groupée de Renovate (« services applicatifs ») */
  group: boolean
}

// Segments trop génériques pour nommer une icône : music-assistant/server → music-assistant.
const GENERIC = new Set(['server', 'app', 'web', 'core', 'docker', 'image', 'api'])

/**
 * Décode un titre de PR Renovate : « chore(deps): Update ghcr.io/x/y Docker tag
 * to v1.2.3 ». Un titre d'un autre format est rendu tel quel.
 */
export function parsePrTitle(title: string): ParsedTitle {
  const body = title.replace(/^\w+(\([^)]*\))?!?:\s*/, '')
  const update = body.match(/^update\s+(?:dependency\s+|module\s+)?(\S+)(\s+(?:docker tag|digest))?\s+to\s+(v?[\w.+-]+)$/i)

  if (update) {
    const [, raw, docker, version] = update
    // Image Docker : le registre (ghcr.io, docker.io/library) ne dit rien d'utile.
    const name = docker ? raw!.replace(/^[^/]+\.[^/]+\//, '').replace(/^library\//, '') : raw!
    const segment = name.split('/').reverse().find((s) => !GENERIC.has(s.toLowerCase())) ?? name
    // immich-server → immich : les icônes portent le nom du projet.
    const icon = segment.toLowerCase().replace(/-(server|app|web)$/, '')

    return { name, version: version!, icon: `sh-${icon}`, group: false }
  }

  const group = body.match(/^update\s+(.+)$/i)
  if (group) return { name: group[1]!, version: null, icon: null, group: true }

  return { name: body, version: null, icon: null, group: false }
}

export type LabelTone = { kind: 'tone'; tone: 'danger' | 'warning' | 'success' } | { kind: 'color'; color: string } | { kind: 'palette'; index: number }

export const PALETTE_SIZE = 6

/**
 * Couleur d'une étiquette : sa vraie couleur GitHub si elle en a une ; sinon
 * (gris par défaut, ex. étiquettes créées par Renovate) d'après son sens, ou une
 * couleur stable tirée de son nom.
 */
export function labelTone(name: string, color: string): LabelTone {
  if (!isNeutral(color)) return { kind: 'color', color }

  const n = name.toLowerCase()
  if (/backup|secu|breaking|danger|critical|major/.test(n)) return { kind: 'tone', tone: 'danger' }
  if (/revue|review|attention|manual|wait/.test(n)) return { kind: 'tone', tone: 'warning' }
  if (/automerge|ready|ok\b/.test(n)) return { kind: 'tone', tone: 'success' }

  let hash = 0
  for (const c of n) hash = (hash * 31 + c.charCodeAt(0)) >>> 0
  return { kind: 'palette', index: hash % PALETTE_SIZE }
}

/** Gris (peu saturé) : la couleur par défaut de GitHub, #ededed, en fait partie. */
function isNeutral(hex: string): boolean {
  const m = hex.match(/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i)
  if (!m) return true
  const [r, g, b] = [m[1]!, m[2]!, m[3]!].map((x) => parseInt(x, 16))
  return Math.max(r!, g!, b!) - Math.min(r!, g!, b!) < 24
}
