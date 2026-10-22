import { useId, useState, type KeyboardEvent, type PointerEvent } from 'react'
import { formatDateTime } from './format'

export type Series = {
  label: string
  values: (number | null)[]
  /** Couleur de la série, par la couleur du texte : « text-indigo-600 dark:text-indigo-400 » */
  className: string
  format: (value: number) => string
}

const W = 100
const H = 40

/**
 * Petit graphique temporel, base à zéro, une ou plusieurs séries sur la même
 * échelle. Le survol (ou le clavier : focus puis flèches) montre un réticule
 * aligné sur la mesure la plus proche et une infobulle avec chaque série.
 * Chaque courbe est remplie d'un dégradé qui s'efface vers le bas (plus léger à
 * plusieurs séries) ; une mesure manquante coupe la ligne. `clipClassName`
 * arrondit la zone tracée (courbe jusqu'aux bords d'un panneau) sans couper l'infobulle.
 */
export function TimeSeries({ label, at, series, max, clipClassName = '' }: { label: string; at: string[]; series: Series[]; max?: number; clipClassName?: string }) {
  const [index, setIndex] = useState<number | null>(null)
  const gradientId = useId()
  const n = at.length

  if (n < 2) {
    return <p className="grid h-full place-items-center text-xs text-zinc-400 dark:text-slate-500">Pas encore de mesures</p>
  }

  const top = max ?? Math.max(1, ...series.flatMap((s) => s.values.filter((v): v is number => v !== null)))
  const x = (i: number) => round((i / (n - 1)) * W)
  const y = (v: number) => round(H - (Math.min(v, top) / top) * H)

  const pick = (clientX: number, el: Element) => {
    const box = el.getBoundingClientRect()
    if (box.width === 0) return
    setIndex(Math.min(n - 1, Math.max(0, Math.round(((clientX - box.left) / box.width) * (n - 1)))))
  }
  const onKey = (e: KeyboardEvent) => {
    if (e.key === 'ArrowLeft') setIndex((i) => Math.max(0, (i ?? n - 1) - 1))
    if (e.key === 'ArrowRight') setIndex((i) => Math.min(n - 1, (i ?? n - 1) + 1))
  }
  const last = series.map((s) => `${s.label} ${lastValue(s.values, s.format)}`).join(', ')

  return (
    <div
      role="img"
      aria-label={`${label} : ${last}`}
      tabIndex={0}
      onPointerMove={(e: PointerEvent) => pick(e.clientX, e.currentTarget)}
      onPointerLeave={() => setIndex(null)}
      onFocus={() => setIndex(n - 1)}
      onBlur={() => setIndex(null)}
      onKeyDown={onKey}
      className="relative h-full rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500"
    >
      <div className={`absolute inset-0 overflow-hidden ${clipClassName}`}>
      <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" className="block size-full" aria-hidden="true">
        <defs>
          {series.map((s, i) => (
            <linearGradient key={s.label} id={`${gradientId}-${i}`} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" stopColor="currentColor" stopOpacity={series.length === 1 ? 0.45 : 0.3} className={s.className} />
              <stop offset="1" stopColor="currentColor" stopOpacity="0" className={s.className} />
            </linearGradient>
          ))}
        </defs>
        <line x1="0" y1={H} x2={W} y2={H} className="stroke-zinc-200 dark:stroke-slate-700" strokeWidth="1" vectorEffect="non-scaling-stroke" />
        {series.map((s, seriesIndex) =>
          segments(s.values).map((seg) => {
            const points = seg.map((i) => `${x(i)},${y(s.values[i]!)}`).join(' ')
            return (
              <g key={`${s.label}-${seg[0]}`} className={s.className}>
                {seg.length === 1 ? (
                  <circle data-isolated cx={x(seg[0]!)} cy={y(s.values[seg[0]!]!)} r="0.8" fill="currentColor" />
                ) : (
                  <>
                    <polygon points={`${x(seg[0]!)},${H} ${points} ${x(seg.at(-1)!)},${H}`} fill={`url(#${gradientId}-${seriesIndex})`} />
                    <polyline points={points} fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
                  </>
                )}
              </g>
            )
          }),
        )}
      </svg>
      </div>

      {index !== null && (
        <>
          <span className="pointer-events-none absolute inset-y-0 w-px bg-zinc-400 dark:bg-slate-500" style={{ left: `${(index / (n - 1)) * 100}%` }} />
          <div
            role="tooltip"
            className="pointer-events-none absolute bottom-full z-10 mb-1 min-w-max rounded-lg bg-white px-2.5 py-1.5 text-xs shadow-lg ring-1 ring-zinc-200 dark:bg-slate-800 dark:ring-slate-700"
            style={index > (n - 1) / 2 ? { right: `${100 - (index / (n - 1)) * 100}%` } : { left: `${(index / (n - 1)) * 100}%` }}
          >
            <p className="mb-0.5 text-zinc-500 dark:text-slate-400">{formatDateTime(at[index]!)}</p>
            {series.map((s) => (
              <p key={s.label} className="flex items-center gap-1.5">
                <span className={`h-0.5 w-3 rounded-full bg-current ${s.className}`} aria-hidden="true" />
                <strong className="font-semibold text-zinc-900 tabular-nums dark:text-slate-50">{s.values[index] !== null ? s.format(s.values[index]!) : '—'}</strong>
                <span className="text-zinc-500 dark:text-slate-400">{s.label}</span>
              </p>
            ))}
          </div>
        </>
      )}
    </div>
  )
}

/** Indices regroupés en suites sans valeur manquante. */
function segments(values: (number | null)[]): number[][] {
  const out: number[][] = []
  let current: number[] = []
  values.forEach((v, i) => {
    if (v === null) {
      if (current.length) out.push(current)
      current = []
    } else current.push(i)
  })
  if (current.length) out.push(current)
  return out
}

function lastValue(values: (number | null)[], format: (v: number) => string): string {
  const v = [...values].reverse().find((x) => x !== null)
  return v === undefined || v === null ? 'pas de mesure' : format(v)
}

const round = (n: number) => Math.round(n * 100) / 100
