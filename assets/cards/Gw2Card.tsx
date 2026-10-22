import type { CardComponent } from './registry'
import { formatNumber, formatRelative } from '../ui/format'

export type Gw2Objective = { period: 'daily' | 'weekly'; title: string; current: number; complete: number; acclaim: number }
type VaultPeriod = { done: number; total: number; claimed: boolean; acclaim: number; resetAt: string }

/** Données de Gw2Card::fetch() côté PHP. Icônes publiques de render.guildwars2.com. */
export type Gw2Data = {
  account: { name: string; fractalLevel: number; wvwRank: number }
  icon: string | null
  coinIcons: { gold: string | null; silver: string | null; copper: string | null }
  /** Monnaies dans l'ordre de l'option `currencies` ; id 1 = l'or, en pièces de cuivre */
  wallet: { id: number; name: string; icon: string; value: number }[]
  delivery: { coins: number; items: number }
  vault: { daily: VaultPeriod; weekly: VaultPeriod; objectives: Gw2Objective[] }
  crafting: { done: number; total: number }
}

const COIN = 1

/** Pièces de cuivre → or, argent, cuivre (1 or = 100 argent = 10 000 cuivre). */
export function coins(copper: number): { gold: number; silver: number; copper: number } {
  return { gold: Math.floor(copper / 10_000), silver: Math.floor(copper / 100) % 100, copper: copper % 100 }
}

export const Gw2Card: CardComponent<{ title: string }, Gw2Data> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null

  return (
    <div className="flex h-full flex-col gap-2.5">
      <header className="flex items-center gap-2">
        {data?.icon && <img src={data.icon} alt="" className="size-7 shrink-0" />}
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? card.props.title}</h3>
        {data && (
          <span className="min-w-0 truncate text-xs text-zinc-500 dark:text-slate-400">
            {data.account.name} · Fractales {data.account.fractalLevel} · McM {data.account.wvwRank}
          </span>
        )}
        <span className="flex-1" />
        {data && data.delivery.coins > 0 && (
          <span title={`${data.delivery.items} objet(s) et de l'or vous attendent au comptoir`} className="shrink-0 rounded-full bg-amber-400 px-2 py-0.5 text-xs font-semibold text-amber-950">
            {formatNumber(coins(data.delivery.coins).gold)} or à récupérer
          </span>
        )}
      </header>

      {data === null ? (
        <div aria-busy="true" className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      ) : (
        <>
          <ul aria-label="Portefeuille" className="flex flex-wrap gap-x-4 gap-y-1 text-sm font-semibold text-zinc-800 tabular-nums dark:text-slate-100">
            {data.wallet.map((c) => (
              <li key={c.id} title={c.id === COIN ? `${c.name} : ${coinsText(c.value)}` : c.name} className="flex items-center gap-1">
                {c.id === COIN ? <Coins value={c.value} icons={data.coinIcons} /> : <><img src={c.icon} alt="" className="size-5" />{formatNumber(c.value)}</>}
              </li>
            ))}
          </ul>

          <div className="grid grid-cols-2 gap-2">
            <Period label="Quotidien" period={data.vault.daily} />
            <Period label="Hebdomadaire" period={data.vault.weekly} />
          </div>

          {data.vault.objectives.length === 0 ? (
            <p className="text-sm text-emerald-700 dark:text-emerald-400">Tous les objectifs sont faits.</p>
          ) : (
            <ul aria-label="Objectifs restants" className="flex min-h-0 flex-1 flex-col gap-1 overflow-hidden">
              {data.vault.objectives.map((o, i) => (
                <li key={`${o.period}-${i}`} title={o.title} className="flex items-center gap-2 text-xs">
                  <span className={`w-11 shrink-0 rounded px-1 text-center text-[0.6rem] font-semibold uppercase ${o.period === 'daily' ? 'bg-sky-500/15 text-sky-700 dark:text-sky-300' : 'bg-violet-500/15 text-violet-700 dark:text-violet-300'}`}>
                    {o.period === 'daily' ? 'Jour' : 'Hebdo'}
                  </span>
                  <span className="min-w-0 flex-1 truncate text-zinc-700 dark:text-slate-200">{o.title}</span>
                  <span className="shrink-0 text-zinc-500 tabular-nums dark:text-slate-400">
                    {o.current}/{o.complete}
                  </span>
                  <span className="shrink-0 font-semibold text-amber-600 tabular-nums dark:text-amber-400">+{o.acclaim}</span>
                </li>
              ))}
            </ul>
          )}

          <p className="text-[0.7rem] text-zinc-500 dark:text-slate-400">
            Artisanat {data.crafting.done}/{data.crafting.total}
          </p>
        </>
      )}
    </div>
  )
}

const coinsText = (value: number) => {
  const c = coins(value)

  return `${formatNumber(c.gold)} or ${c.silver} argent ${c.copper} cuivre`
}

function Coins({ value, icons }: { value: number; icons: Gw2Data['coinIcons'] }) {
  const c = coins(value)
  const part = (n: number, icon: string | null) => (
    <span className="flex items-center gap-0.5">
      {n}
      {icon && <img src={icon} alt="" className="size-3.5" />}
    </span>
  )

  return (
    <span className="flex items-center gap-1.5">
      {part(c.gold, icons.gold)} {part(c.silver, icons.silver)} {part(c.copper, icons.copper)}
    </span>
  )
}

function Period({ label, period }: { label: string; period: VaultPeriod }) {
  const percent = period.total > 0 ? Math.round((period.done / period.total) * 100) : 0
  const finished = period.done >= period.total

  return (
    <div role="group" aria-label={label} className="rounded-lg bg-zinc-50 px-2.5 py-1.5 dark:bg-slate-800">
      <p className="flex items-baseline gap-1.5 text-xs">
        <span className="font-semibold text-zinc-800 dark:text-slate-100">{label}</span>
        <span className="tabular-nums text-zinc-600 dark:text-slate-300">
          {period.done}/{period.total}
        </span>
        <span className="flex-1" />
        <span className="text-[0.65rem] text-zinc-500 dark:text-slate-400">{formatRelative(period.resetAt, new Date(), 'short')}</span>
      </p>
      <span className="mt-1 block h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-slate-700">
        <span className={`block h-full rounded-full ${finished ? 'bg-emerald-500' : 'bg-amber-500'}`} style={{ width: `${percent}%` }} />
      </span>
    </div>
  )
}
