import type { ProfileColor } from '../http/types'

// Classes écrites en entier : Tailwind ne détecte pas les noms construits à la volée.
export const profileBadge: Record<ProfileColor, string> = {
  slate: 'bg-slate-500',
  indigo: 'bg-indigo-500',
  sky: 'bg-sky-500',
  emerald: 'bg-emerald-500',
  amber: 'bg-amber-500',
  rose: 'bg-rose-500',
  violet: 'bg-violet-500',
  teal: 'bg-teal-500',
}
