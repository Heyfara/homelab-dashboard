import { useLayoutEffect, useMemo, useRef, useState } from 'react'
import { formatRelative, formatSpeed } from '../../ui/format'
import type { LinkRate, NetworkData, NetworkRates } from '../NetworkCard'
import { deviceLabel, isUnknown, placeLabel, weakSignal } from './devices'
import { DeviceIcon } from './DeviceIcon'
import { buildZones, flowSpeed, layout, related, type DiagramLink, type DiagramNode, type RateRef, type ZoneTone } from './layout'

// Classes écrites en entier : Tailwind ne détecte pas `text-zone-${tone}`.
const TONES: Record<ZoneTone, { disc: string; stroke: string; zone: string; label: string }> = {
  core: { disc: 'border-zone-core bg-zone-core/10 text-zone-core', stroke: 'stroke-zone-core', zone: 'border-zone-core/40 bg-zone-core/5', label: 'text-zone-core' },
  wired: { disc: 'border-zone-wired bg-zone-wired/10 text-zone-wired', stroke: 'stroke-zone-wired', zone: 'border-zone-wired/40 bg-zone-wired/5', label: 'text-zone-wired' },
  ap1: { disc: 'border-zone-ap1 bg-zone-ap1/10 text-zone-ap1', stroke: 'stroke-zone-ap1', zone: 'border-zone-ap1/40 bg-zone-ap1/5', label: 'text-zone-ap1' },
  ap2: { disc: 'border-zone-ap2 bg-zone-ap2/10 text-zone-ap2', stroke: 'stroke-zone-ap2', zone: 'border-zone-ap2/40 bg-zone-ap2/5', label: 'text-zone-ap2' },
  ap3: { disc: 'border-zone-ap3 bg-zone-ap3/10 text-zone-ap3', stroke: 'stroke-zone-ap3', zone: 'border-zone-ap3/40 bg-zone-ap3/5', label: 'text-zone-ap3' },
  other: { disc: 'border-zone-other bg-zone-other/10 text-zone-other', stroke: 'stroke-zone-other', zone: 'border-zone-other/40 bg-zone-other/5', label: 'text-zone-other' },
  uncertain: { disc: 'border-zone-uncertain bg-zone-uncertain/10 text-zone-uncertain', stroke: 'stroke-zone-uncertain', zone: 'border-dashed border-zone-uncertain/50', label: 'text-zone-uncertain' },
}

const CORE_LABELS = { internet: 'Internet', box: 'Freebox', switch: 'Switch' }

/** Débit d'un lien d'après le flux `rates`. */
export function rateOf(rates: NetworkRates | null, ref: RateRef | null): LinkRate | undefined {
  if (rates === null || ref === null) return undefined
  if (ref.kind === 'wan') return { down: rates.wan.down, up: rates.wan.up }
  const ports = rates.accessPoints[ref.ap]?.ports
  return ports !== undefined && !Array.isArray(ports) ? ports[ref.port] : undefined
}

/** Largeur disponible ; 1200 tant qu'elle n'est pas mesurée (tests, premier rendu). */
function useWidth() {
  const ref = useRef<HTMLDivElement>(null)
  const [width, setWidth] = useState(1200)
  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return
    const measure = () => el.clientWidth > 0 && setWidth(el.clientWidth)
    measure()
    if (typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(measure)
    observer.observe(el)
    return () => observer.disconnect()
  }, [])

  return [ref, width] as const
}

type Props = { data: NetworkData; rates: NetworkRates | null; selected: string | null; onSelect: (key: string) => void }

export function TopologyDiagram({ data, rates, selected, onSelect }: Props) {
  const [ref, width] = useWidth()
  const [hover, setHover] = useState<string | null>(null)
  const diagram = useMemo(() => layout(buildZones(data), width), [data, width])
  const hot = hover !== null ? related(diagram, hover) : null
  const dim = (key: string) => hot !== null && !hot.has(key)

  return (
    <div ref={ref} className="overflow-x-auto">
      <div className="relative" style={{ width: diagram.width, height: diagram.height }}>
        {diagram.zones.map((z) => (
          <div key={z.key} className={`absolute rounded-2xl border ${TONES[z.tone].zone}`} style={{ left: z.x, top: z.y, width: z.w, height: z.h }}>
            <span className={`absolute top-2 right-3 text-[11px] font-semibold tracking-wider uppercase ${TONES[z.tone].label}`}>{z.label}</span>
          </div>
        ))}
        <svg width={diagram.width} height={diagram.height} className="pointer-events-none absolute inset-0" aria-hidden="true">
          {diagram.links.map((l) => (
            <Link key={`${l.from}>${l.to}`} link={l} rate={rateOf(rates, l.rate)} lit={hot !== null && hot.has(l.from) && hot.has(l.to)} dim={hot !== null && !(hot.has(l.from) && hot.has(l.to))} />
          ))}
        </svg>
        {diagram.nodes.map((n) => (
          <Node
            key={n.key}
            node={n}
            data={data}
            rate={rateOf(rates, diagram.links.find((l) => l.to === n.key)?.rate ?? null)}
            selected={selected === n.key}
            dim={dim(n.key)}
            onSelect={() => onSelect(n.key)}
            onHover={(on) => setHover(on ? n.key : null)}
          />
        ))}
      </div>
    </div>
  )
}

function Link({ link: l, rate, lit, dim }: { link: DiagramLink; rate: LinkRate | undefined; lit: boolean; dim: boolean }) {
  const down = flowSpeed(rate?.down)
  const up = flowSpeed(rate?.up)
  const opacity = dim ? 0.1 : lit ? 1 : 0.5

  return (
    <g data-link={`${l.from}>${l.to}`}>
      <path d={l.d} fill="none" className={`${TONES[l.tone].stroke} transition-opacity`} strokeWidth={lit ? 3 : 2} strokeDasharray={l.kind === 'wifi' ? '4 6' : undefined} opacity={opacity} />
      {down !== null && <path d={l.d} className={`net-flow net-flow-${down} ${TONES[l.tone].stroke}`} opacity={dim ? 0.08 : 1} />}
      {up !== null && <path d={l.d} className={`net-flow net-flow-up net-flow-${up} stroke-zinc-700 dark:stroke-slate-100`} opacity={dim ? 0.08 : 0.7} />}
    </g>
  )
}

type NodeProps = { node: DiagramNode; data: NetworkData; rate: LinkRate | undefined; selected: boolean; dim: boolean; onSelect: () => void; onHover: (on: boolean) => void }

function Node({ node: n, data, rate, selected, dim, onSelect, onHover }: NodeProps) {
  const ring = selected ? 'ring-2 ring-zinc-900 ring-offset-2 ring-offset-white dark:ring-slate-50 dark:ring-offset-slate-900' : ''
  const events = { onClick: onSelect, onMouseEnter: () => onHover(true), onMouseLeave: () => onHover(false), onFocus: () => onHover(true), onBlur: () => onHover(false) }
  const fade = `transition-opacity ${dim ? 'opacity-30' : ''}`

  if (n.core !== null) {
    const label = CORE_LABELS[n.core]
    const sub = n.core === 'switch' ? data.wiredZone : n.core === 'box' ? 'routeur' : null
    return (
      <button type="button" aria-label={label} aria-pressed={selected} data-dim={dim} className={`absolute flex w-24 flex-col items-center gap-1 rounded-xl focus-visible:outline-2 focus-visible:outline-indigo-500 ${fade}`} style={{ left: n.x - 48, top: n.y - 26 }} {...events}>
        <span className={`grid size-13 place-items-center rounded-2xl border-2 ${TONES.core.disc} ${ring}`}>
          <DeviceIcon kind={n.core === 'box' ? 'box' : n.core} className="size-6" />
        </span>
        <span className="text-xs leading-tight font-semibold text-zinc-900 dark:text-slate-50">{label}</span>
        {sub !== null && <span className="-mt-1 text-[11px] text-zinc-500 dark:text-slate-400">{sub}</span>}
      </button>
    )
  }

  const d = n.device
  const label = d !== null ? deviceLabel(d) : (n.ap?.id ?? '?')
  const p = d?.place ?? null
  const stale = p?.seenAt != null
  const sub = n.ap !== null ? (n.ap.error !== null ? 'injoignable' : `point d’accès · ${n.ap.id}`) : p === null ? 'position incertaine' : p.link === 'wifi' ? placeLabel(p).split(' · ')[1] : p.ap !== null ? `câble ${p.port}` : 'câble'
  const dot = n.ap?.error ? 'bg-amber-500' : stale ? 'bg-zinc-400 dark:bg-slate-500' : p !== null && weakSignal(p) ? 'bg-amber-500' : 'bg-emerald-500'

  return (
    <button
      type="button"
      aria-label={`${label}${d?.ip ? `, ${d.ip}` : ''}`}
      aria-pressed={selected}
      data-dim={dim}
      className={`absolute flex h-12 items-center gap-3 rounded-xl pr-2 text-left hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-slate-800 ${selected ? 'bg-zinc-100 dark:bg-slate-800' : ''} ${stale ? 'opacity-60' : ''} ${fade}`}
      style={{ left: n.left, top: n.y - 24, width: n.w }}
      {...events}
    >
      <span className={`relative grid size-10 shrink-0 place-items-center rounded-xl border-2 ${TONES[n.tone].disc} ${d !== null && isUnknown(d) ? 'net-pulse' : ''}`}>
        <DeviceIcon kind={d?.type ?? 'network'} />
        <span className={`absolute -right-1 -bottom-1 size-3 rounded-full border-2 border-white dark:border-slate-900 ${dot}`} />
      </span>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="truncate text-sm font-semibold text-zinc-900 dark:text-slate-50">{label}</span>
        <span className="truncate text-xs text-zinc-500 dark:text-slate-400">
          {d?.ip !== null && d?.ip !== undefined && <span className="font-mono">.{d.ip.split('.').at(-1)} · </span>}
          {sub}
          {p?.signal != null && ` · ${p.signal} dBm`}
          {stale && ` · vu ${formatRelative(p!.seenAt!, new Date(), 'short')}`}
        </span>
      </span>
      {rate !== undefined && (
        <span className="flex shrink-0 flex-col items-end font-mono text-[11px] leading-tight tabular-nums">
          <span className="text-down">↓ {formatSpeed(rate.down)}</span>
          <span className="text-zinc-600 dark:text-slate-300">↑ {formatSpeed(rate.up)}</span>
        </span>
      )}
    </button>
  )
}
