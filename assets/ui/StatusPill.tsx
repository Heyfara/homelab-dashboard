export type Tone = 'ok' | 'warn' | 'down' | 'unknown'

const tones: Record<Tone, string> = {
  ok: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  warn: 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  down: 'bg-rose-50 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
  unknown: 'bg-zinc-100 text-zinc-600 dark:bg-slate-800 dark:text-slate-400',
}

const dots: Record<Tone, string> = {
  ok: 'bg-emerald-500',
  warn: 'bg-amber-500',
  down: 'bg-rose-500',
  unknown: 'bg-zinc-400',
}

export function StatusPill({ tone, label }: { tone: Tone; label: string }) {
  return (
    <span className={`inline-flex w-fit items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${tones[tone]}`}>
      <span className={`size-1.5 rounded-full ${dots[tone]}`} aria-hidden="true" />
      {label}
    </span>
  )
}
