import type { CardComponent } from './registry'
import { weather } from '../ui/weather'

/** Props de WeatherCard::describe() côté PHP. */
export type WeatherProps = { title: string; place: string | null }

type Hour = { time: string; temperature: number; code: number; isDay: boolean; rainChance: number | null }

/** Données de WeatherCard::fetch() côté PHP. Heures locales du lieu. */
export type WeatherData = {
  now: { time: string; temperature: number; feelsLike: number; code: number; isDay: boolean; wind: number }
  hours: Hour[]
  days: { date: string; code: number; min: number; max: number; rainChance: number | null }[]
  sun: { sunrise: string | null; sunset: string | null }
}

const weekday = new Intl.DateTimeFormat('fr-FR', { weekday: 'short' })

/** Pluie « probable » à partir de ce pourcentage : affichée en bleu. */
const RAINY = 40

function Icon({ code, isDay, className }: { code: number; isDay: boolean; className: string }) {
  const w = weather(code, isDay)

  return (
    <span role="img" aria-label={w.label} title={w.label} className={className}>
      {w.icon}
    </span>
  )
}

function Rain({ chance }: { chance: number | null }) {
  if (chance === null || chance === 0) return <span className="h-4" />

  return <span className={`text-[0.65rem] tabular-nums ${chance >= RAINY ? 'font-semibold text-sky-600 dark:text-sky-400' : 'text-zinc-400'}`}>{chance} %</span>
}

export const WeatherCard: CardComponent<WeatherProps, WeatherData> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null
  const hours = data?.hours ?? []

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? card.props.title}</h3>
        <span className="flex-1" />
        {data?.sun.sunrise && data.sun.sunset && (
          <span className="shrink-0 text-xs text-zinc-500 tabular-nums dark:text-slate-400" title="Lever et coucher du soleil">
            <span aria-hidden="true">🌅 </span>
            {data.sun.sunrise} · {data.sun.sunset}
          </span>
        )}
      </header>

      {data === null ? (
        <div aria-busy="true" className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
      ) : (
        <>
          <div role="group" aria-label="Maintenant" className="flex items-center gap-3">
            <Icon code={data.now.code} isDay={data.now.isDay} className="text-5xl leading-none" />
            <span className="text-4xl font-bold text-zinc-900 tabular-nums dark:text-slate-50">{data.now.temperature}°</span>
            <span className="flex min-w-0 flex-col">
              <span className="truncate font-medium text-zinc-800 dark:text-slate-100">{weather(data.now.code, data.now.isDay).label}</span>
              <span className="truncate text-xs text-zinc-500 tabular-nums dark:text-slate-400">
                ressenti {data.now.feelsLike}° · vent {data.now.wind} km/h
              </span>
            </span>
          </div>

          <ul aria-label="Prochaines heures" className="flex justify-between gap-0.5 rounded-lg bg-zinc-50 px-2 py-1.5 dark:bg-slate-800/60">
            {hours.map((h) => (
              <li key={h.time} className="flex min-w-0 flex-col items-center text-[0.7rem] tabular-nums">
                <span className="text-zinc-500 dark:text-slate-400">{Number(h.time.slice(0, 2))} h</span>
                <Icon code={h.code} isDay={h.isDay} className="text-base leading-6" />
                <span className="font-semibold text-zinc-800 dark:text-slate-100">{h.temperature}°</span>
                <Rain chance={h.rainChance} />
              </li>
            ))}
          </ul>

          <ul aria-label="Prochains jours" className="flex justify-between gap-1">
            {data.days.map((d, i) => (
              <li key={d.date} className="flex flex-col items-center text-xs tabular-nums">
                <span className="text-zinc-500 dark:text-slate-400">{i === 0 ? 'Auj.' : weekday.format(new Date(`${d.date}T12:00:00`))}</span>
                <Icon code={d.code} isDay className="text-xl leading-6" />
                <span className="flex gap-1">
                  <span className="font-semibold text-zinc-800 dark:text-slate-100">{d.max}°</span>
                  <span className="text-zinc-400">{d.min}°</span>
                </span>
                <Rain chance={d.rainChance} />
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  )
}
