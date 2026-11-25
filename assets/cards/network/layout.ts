import type { AccessPointInfo, NetworkData } from '../NetworkCard'
import { deviceLabel, UNCERTAIN, type Device } from './devices'

/*
 * Schéma de l'onglet Réseau, calculé sans rendu : Internet, la box et le
 * switch en tête, puis une zone par endroit (la baie, chaque point d'accès,
 * la position incertaine), en colonnes côte à côte si la largeur le permet,
 * sinon empilées. Les liens partent du switch par un « bus » horizontal et
 * descendent en angle droit vers chaque appareil.
 */

/** Couleur d'une zone (variables --color-zone-* de app.css), dans l'ordre fixe des points d'accès. */
export type ZoneTone = 'core' | 'wired' | 'ap1' | 'ap2' | 'ap3' | 'other' | 'uncertain'

const AP_TONES: ZoneTone[] = ['ap1', 'ap2', 'ap3']

export type Zone = {
  key: string
  label: string
  tone: ZoneTone
  ap: AccessPointInfo | null
  /** Appareil du point d'accès lui-même, en tête de sa zone */
  head: Device | null
  devices: Device[]
}

/** Débit d'un lien : celui d'Internet, ou d'un port d'un point d'accès. */
export type RateRef = { kind: 'wan' } | { kind: 'port'; ap: string; port: string }

export type CoreKey = 'internet' | 'box' | 'switch'

export type DiagramNode = {
  /** MAC d'un appareil, clé du cœur, ou `ap:<id>` pour un point d'accès absent de la Freebox */
  key: string
  core: CoreKey | null
  device: Device | null
  ap: AccessPointInfo | null
  tone: ZoneTone
  /** Centre du disque */
  x: number
  y: number
  /** Ligne d'appareil : bord gauche du disque et largeur de la ligne */
  left: number
  w: number
  parent: string | null
}

export type DiagramLink = { from: string; to: string; d: string; kind: 'cable' | 'wifi'; tone: ZoneTone; rate: RateRef | null }

export type DiagramZone = { key: string; label: string; tone: ZoneTone; x: number; y: number; w: number; h: number }

export type Diagram = { width: number; height: number; nodes: DiagramNode[]; links: DiagramLink[]; zones: DiagramZone[] }

const byName = (a: Device, b: Device) => (a.name ?? a.vendor ?? a.mac).localeCompare(b.name ?? b.vendor ?? b.mac, 'fr')

export function buildZones(data: NetworkData): Zone[] {
  const heads = new Set(data.accessPoints.map((ap) => ap.device).filter((m) => m !== null))
  const online = data.devices.filter((d) => d.online && !heads.has(d.mac))
  const zones: Zone[] = [
    { key: 'wired', label: data.wiredZone, tone: 'wired', ap: null, head: null, devices: online.filter((d) => d.place !== null && d.place.ap === null).sort(byName) },
  ]
  data.accessPoints.forEach((ap, i) => {
    const mine = online.filter((d) => d.place?.ap === ap.id)
    zones.push({
      key: `ap:${ap.id}`,
      label: ap.zone,
      tone: AP_TONES[i] ?? 'other',
      ap,
      head: data.devices.find((d) => d.mac === ap.device) ?? null,
      // Câblés d'abord (par port), puis le Wi-Fi par nom.
      devices: [...mine.filter((d) => d.place!.link === 'cable').sort((a, b) => (a.place!.port ?? '').localeCompare(b.place!.port ?? '')), ...mine.filter((d) => d.place!.link !== 'cable').sort(byName)],
    })
  })
  const lost = online.filter((d) => d.place === null).sort(byName)
  if (lost.length > 0) zones.push({ key: 'uncertain', label: UNCERTAIN, tone: 'uncertain', ap: null, head: null, devices: lost })

  return zones
}

// Géométrie (px). Disques : 52 au cœur, 40 pour les appareils.
const CORE_Y: Record<CoreKey, number> = { internet: 36, box: 160, switch: 284 }
const CORE_LABEL = 66 // du centre du disque au bas de son libellé et de son sous-titre
const BUS_Y = CORE_Y.switch + CORE_LABEL + 14
const ZONES_Y = BUS_Y + 18
const FIRST = 46 // du haut de la zone au centre du premier appareil
const ROW = 56
const DISC = 20 // demi-disque d'un appareil
const INDENT = 32
const PAD = 12
const GAP = 16
const MIN_COL = 250

export function layout(zones: Zone[], width: number): Diagram {
  const cx = Math.round(width / 2)
  const nodes: DiagramNode[] = (['internet', 'box', 'switch'] as const).map((core, i, all) => ({
    key: core,
    core,
    device: null,
    ap: null,
    tone: 'core' as const,
    x: cx,
    y: CORE_Y[core],
    left: cx - 26,
    w: 52,
    parent: i === 0 ? null : all[i - 1]!,
  }))
  const links: DiagramLink[] = [
    { from: 'internet', to: 'box', d: `M${cx} ${CORE_Y.internet + CORE_LABEL} V${CORE_Y.box - 28}`, kind: 'cable', tone: 'core', rate: { kind: 'wan' } },
    { from: 'box', to: 'switch', d: `M${cx} ${CORE_Y.box + CORE_LABEL} V${CORE_Y.switch - 28}`, kind: 'cable', tone: 'core', rate: { kind: 'wan' } },
  ]
  const bus = (x: number) => `M${cx} ${CORE_Y.switch + CORE_LABEL} V${BUS_Y} H${x}`

  const side = width >= zones.length * MIN_COL + (zones.length - 1) * GAP
  const colW = side ? (width - (zones.length - 1) * GAP) / zones.length : width
  const boxes: DiagramZone[] = []
  let y = ZONES_Y
  zones.forEach((z, i) => {
    const zx = side ? Math.round(i * (colW + GAP)) : 0
    const zy = side ? ZONES_Y : y
    const zw = Math.round(colW)
    const rows = (z.ap !== null ? 1 : 0) + z.devices.length
    const h = FIRST + Math.max(rows - 1, 0) * ROW + DISC + 18
    boxes.push({ key: z.key, label: z.label, tone: z.tone, x: zx, y: zy, w: zw, h })
    y = zy + h + GAP

    const trunk = zx + PAD + DISC
    const at = (row: number, depth: number) => ({ left: zx + PAD + depth * INDENT, y: zy + FIRST + row * ROW })
    const item = (key: string, device: Device | null, row: number, depth: number, parent: string | null): DiagramNode => {
      const p = at(row, depth)
      return { key, core: null, device, ap: depth === 0 ? z.ap : null, tone: z.tone, x: p.left + DISC, y: p.y, left: p.left, w: zx + zw - 8 - p.left, parent }
    }

    if (z.ap !== null) {
      const ap = z.ap
      const head = item(z.head?.mac ?? `ap:${ap.id}`, z.head, 0, 0, 'switch')
      nodes.push(head)
      links.push({ from: 'switch', to: head.key, d: `${bus(head.x)} V${head.y - DISC}`, kind: 'cable', tone: z.tone, rate: { kind: 'port', ap: ap.id, port: ap.uplink } })
      z.devices.forEach((d, n) => {
        const node = item(d.mac, d, n + 1, 1, head.key)
        nodes.push(node)
        const cable = d.place?.link === 'cable'
        links.push({
          from: head.key,
          to: d.mac,
          d: `M${head.x} ${head.y + DISC} V${node.y} H${node.left}`,
          kind: cable ? 'cable' : 'wifi',
          tone: z.tone,
          rate: cable && d.place?.port ? { kind: 'port', ap: ap.id, port: d.place.port } : null,
        })
      })
    } else {
      // Baie : chaque appareil sur le switch. Incertaine : pas de lien, on ne sait pas.
      z.devices.forEach((d, n) => {
        const node = item(d.mac, d, n, 1, z.tone === 'uncertain' ? null : 'switch')
        nodes.push(node)
        if (z.tone !== 'uncertain') links.push({ from: 'switch', to: d.mac, d: `${bus(trunk)} V${node.y} H${node.left}`, kind: 'cable', tone: z.tone, rate: null })
      })
    }
  })

  const height = Math.max(...boxes.map((b) => b.y + b.h), ZONES_Y) + 8
  return { width, height, nodes, links, zones: boxes }
}

/** Ce qui s'éclaire au survol d'un nœud : son chemin jusqu'à Internet et tout ce qui en dépend. */
export function related(diagram: Diagram, key: string): Set<string> {
  const parent = new Map(diagram.nodes.map((n) => [n.key, n.parent]))
  const out = new Set<string>([key])
  for (let p = parent.get(key); p; p = parent.get(p)) out.add(p)
  const walk = (k: string) =>
    diagram.nodes.filter((n) => n.parent === k).forEach((n) => {
      out.add(n.key)
      walk(n.key)
    })
  walk(key)

  return out
}

/** Rythme des points animés d'un lien, d'après son débit (octets/s) ; null : immobile. */
export function flowSpeed(rate: number | undefined): 'slow' | 'mid' | 'fast' | null {
  if (rate === undefined || rate < 1_000) return null
  if (rate < 100_000) return 'slow'
  if (rate < 5_000_000) return 'mid'
  return 'fast'
}

const CORE_PATH = ['Internet', 'Freebox', 'Switch']

/** Noms du chemin d'Internet jusqu'au nœud ; vide si sa position est incertaine. */
export function pathOf(data: NetworkData, key: string): string[] {
  const core = (['internet', 'box', 'switch'] as const).indexOf(key as CoreKey)
  if (core >= 0) return CORE_PATH.slice(0, core + 1)
  const d = data.devices.find((x) => x.mac === key)
  const ap = data.accessPoints.find((a) => a.device === key || `ap:${a.id}` === key)
  if (ap !== undefined) return [...CORE_PATH, d !== undefined ? deviceLabel(d) : ap.id]
  if (d === undefined || d.place === null) return []
  if (d.place.ap === null) return [...CORE_PATH, deviceLabel(d)]
  const head = data.accessPoints.find((a) => a.id === d.place!.ap)
  const headDevice = data.devices.find((x) => x.mac === head?.device)

  return [...CORE_PATH, headDevice !== undefined ? deviceLabel(headDevice) : (head?.id ?? '?'), deviceLabel(d)]
}
