import { useId, type ReactNode } from 'react'

const R = 16
const C = 2 * Math.PI * R

/**
 * Jauge en anneau pour une proportion qui a un vrai maximum (XP d'un niveau…).
 * Piste et arc dans la couleur du texte courant (`className`), ou arc en
 * dégradé entre deux couleurs (`gradient`).
 */
export function RingGauge({
  value,
  label,
  children,
  gradient,
  className = 'size-16 text-indigo-600 dark:text-indigo-400',
}: {
  value: number
  label: string
  children?: ReactNode
  gradient?: [string, string]
  className?: string
}) {
  const v = Math.min(1, Math.max(0, value))
  const id = useId()

  return (
    <div role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(v * 100)} title={label} className={`relative shrink-0 ${className}`}>
      <svg viewBox="0 0 40 40" className="size-full -rotate-90" aria-hidden="true">
        {gradient && (
          <defs>
            <linearGradient id={id} x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stopColor={gradient[0]} />
              <stop offset="1" stopColor={gradient[1]} />
            </linearGradient>
          </defs>
        )}
        <circle cx="20" cy="20" r={R} fill="none" stroke="currentColor" strokeOpacity="0.15" strokeWidth="4" />
        <circle
          data-arc
          cx="20"
          cy="20"
          r={R}
          fill="none"
          stroke={gradient ? `url(#${id})` : 'currentColor'}
          strokeWidth="4"
          strokeLinecap="round"
          strokeDasharray={C}
          strokeDashoffset={C * (1 - v)}
        />
      </svg>
      <div className="absolute inset-0 grid place-items-center">{children}</div>
    </div>
  )
}
