import { useState, type KeyboardEvent, type PointerEvent } from 'react'
import { formatDay } from './format'
import type { Series } from './TimeSeries'

/**
 * Barres empilées, une par jour (dates « AAAA-MM-JJ »), base à zéro, échelle
 * du plus haut total. Le survol (ou le clavier : focus puis flèches) montre
 * le jour, chaque série et le total. Les segments sont séparés de 2 px, le
 * haut de la barre est arrondi.
 */
export function DailyBars({ label, at, series, formatTotal }: { label: string; at: string[]; series: Series[]; formatTotal?: (value: number) => string }) {
  const [index, setIndex] = useState<number | null>(null)
  const n = at.length

  if (n === 0) {
    return <p className="grid h-full place-items-center text-xs text-zinc-400 dark:text-slate-500">Pas encore de mesures</p>
  }

  const totals = at.map((_, i) => series.reduce((sum, s) => sum + (s.values[i] ?? 0), 0))
  const top = Math.max(...totals) || 1

  const pick = (clientX: number, el: Element) => {
    const box = el.getBoundingClientRect()
    if (box.width === 0) return
    setIndex(Math.min(n - 1, Math.max(0, Math.floor(((clientX - box.left) / box.width) * n))))
  }
  const onKey = (e: KeyboardEvent) => {
    if (e.key === 'ArrowLeft') setIndex((i) => Math.max(0, (i ?? n - 1) - 1))
    if (e.key === 'ArrowRight') setIndex((i) => Math.min(n - 1, (i ?? n - 1) + 1))
  }
  const center = index === null ? 0 : ((index + 0.5) / n) * 100

  return (
    <div
      role="img"
      aria-label={`${label} : ${formatDay(at[n - 1]!)}, ${series.map((s) => `${s.label} ${s.values[n - 1] !== null ? s.format(s.values[n - 1]!) : '—'}`).join(', ')}`}
      tabIndex={0}
      onPointerMove={(e: PointerEvent) => pick(e.clientX, e.currentTarget)}
      onPointerLeave={() => setIndex(null)}
      onFocus={() => setIndex(n - 1)}
      onBlur={() => setIndex(null)}
      onKeyDown={onKey}
      className="relative h-full rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500"
    >
      <div className="flex h-full items-end gap-[2px] border-b border-zinc-200 dark:border-slate-700" aria-hidden="true">
        {at.map((day, i) => (
          <div key={day} data-bar className={`flex h-full min-w-0 flex-1 flex-col-reverse gap-[2px] ${index !== null && index !== i ? 'opacity-60' : ''}`}>
            {series.map((s) => {
              const v = s.values[i]
              if (v === null || v === undefined || v <= 0) return null
              return <div key={s.label} data-segment className={`w-full shrink-0 bg-current last:rounded-t-[3px] ${s.className}`} style={{ height: `${round((v / top) * 100)}%` }} />
            })}
          </div>
        ))}
      </div>

      {index !== null && (
        <div
          role="tooltip"
          className="pointer-events-none absolute bottom-full z-10 mb-1 min-w-max rounded-lg bg-white px-2.5 py-1.5 text-xs shadow-lg ring-1 ring-zinc-200 dark:bg-slate-800 dark:ring-slate-700"
          style={center > 50 ? { right: `${100 - center}%` } : { left: `${center}%` }}
        >
          <p className="mb-0.5 text-zinc-500 dark:text-slate-400">{formatDay(at[index]!)}</p>
          {series.map((s) => (
            <p key={s.label} className="flex items-center gap-1.5">
              <span className={`size-2 rounded-sm bg-current ${s.className}`} aria-hidden="true" />
              <strong className="font-semibold text-zinc-900 tabular-nums dark:text-slate-50">{s.values[index] !== null ? s.format(s.values[index]!) : '—'}</strong>
              <span className="text-zinc-500 dark:text-slate-400">{s.label}</span>
            </p>
          ))}
          {formatTotal && series.length > 1 && (
            <p className="mt-0.5 border-t border-zinc-100 pt-0.5 dark:border-slate-700">
              <strong className="font-semibold text-zinc-900 tabular-nums dark:text-slate-50">{formatTotal(totals[index]!)}</strong>{' '}
              <span className="text-zinc-500 dark:text-slate-400">Total</span>
            </p>
          )}
        </div>
      )}
    </div>
  )
}

const round = (v: number) => Math.round(v * 10) / 10
