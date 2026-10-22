const W = 100
const H = 32
const TOP = 4

/**
 * Petite courbe d'évolution, base à zéro, dans la couleur du texte courant
 * (`className` : text-down, text-up…). Le dernier point est marqué.
 */
export function Sparkline({ values, label, className }: { values: number[]; label: string; className: string }) {
  if (values.length < 2) {
    // Pas encore assez de mesures : la ligne de base annonce la courbe à venir.
    return (
      <div role="img" aria-label={`${label} : mesures en cours`} className={`relative ${className}`}>
        <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" className="block size-full" aria-hidden="true">
          <line x1="0" y1={H} x2={W} y2={H} className="stroke-zinc-300 dark:stroke-slate-600" strokeWidth="1" strokeDasharray="3 3" vectorEffect="non-scaling-stroke" />
        </svg>
      </div>
    )
  }

  const max = Math.max(...values)
  const y = (v: number) => (max === 0 ? H : H - (v / max) * (H - TOP))
  const points = values.map((v, i) => `${round((i / (values.length - 1)) * W)},${round(y(v))}`)
  const last = y(values.at(-1)!)

  return (
    <div role="img" aria-label={label} title={label} className={`relative ${className}`}>
      <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" className="block size-full overflow-visible" aria-hidden="true">
        <line x1="0" y1={H} x2={W} y2={H} className="stroke-zinc-200 dark:stroke-slate-700" strokeWidth="1" vectorEffect="non-scaling-stroke" />
        <polygon points={`0,${H} ${points.join(' ')} ${W},${H}`} fill="currentColor" fillOpacity="0.12" />
        <polyline points={points.join(' ')} fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
      </svg>
      <span data-endpoint className="absolute -right-1 size-2 -translate-y-1/2 rounded-full bg-current ring-2 ring-white dark:ring-slate-900" style={{ top: `${round((last / H) * 100)}%` }} />
    </div>
  )
}

const round = (n: number) => Math.round(n * 100) / 100
