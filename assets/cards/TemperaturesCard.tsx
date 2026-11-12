import type { CardResult, CardView } from '../http/types'
import { ExternalLink } from '../ui/ExternalLink'
import { formatNumber } from '../ui/format'
import { FlameIcon } from '../ui/icons'
import { ServiceIcon } from '../ui/ServiceIcon'
import { Sparkline } from '../ui/Sparkline'

/** Props de TemperaturesCard::describe() côté PHP. */
export type TemperaturesProps = { title: string; url: string }

export type Room = {
  area: string
  name: string
  temperature: number | null
  humidity: number | null
  /** Moyenne de chaque heure (°C), alignée sur `at` */
  history: (number | null)[]
  /** Thermostat de la pièce : consigne, action (heating, idle…) et mode HA */
  climate: { target: number | null; action: string | null; mode: string } | null
}

/** Données de TemperaturesCard::fetch() côté PHP. */
export type TemperaturesData = {
  outdoor: { name: string; temperature: number | null; humidity: number | null } | null
  at: string[]
  rooms: Room[]
}

const tenth = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })

/** « 17,6 °C » */
export const formatTemperature = (value: number) => `${tenth.format(value)} °C`

export function TemperaturesCard({ card, result }: { card: CardView<TemperaturesProps>; result: CardResult<TemperaturesData> | null }) {
  const { title, url } = card.props
  const data = result?.status === 'ok' ? result.data : null

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon="sh-home-assistant" name="Home Assistant" />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        <span className="flex-1" />
        {data?.outdoor && (
          <p role="group" aria-label="Extérieur" className="flex shrink-0 items-baseline gap-2 text-sm text-zinc-500 tabular-nums dark:text-slate-400">
            Extérieur
            <strong className="font-semibold text-zinc-900 dark:text-slate-50">{data.outdoor.temperature !== null ? formatTemperature(data.outdoor.temperature) : '—'}</strong>
            {data.outdoor.humidity !== null && <span>{formatNumber(Math.round(data.outdoor.humidity))} %</span>}
          </p>
        )}
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="grid min-h-0 flex-1 grid-cols-3 gap-2">
          {Array.from({ length: 6 }, (_, i) => (
            <div key={i} className="animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
          ))}
        </div>
      ) : (
        <ul className={`grid min-h-0 flex-1 auto-rows-fr gap-2 ${data.rooms.length > 4 ? 'grid-cols-2 sm:grid-cols-3' : 'grid-cols-2'}`}>
          {data.rooms.map((room) => (
            <RoomTile key={room.area} room={room} />
          ))}
        </ul>
      )}
    </div>
  )
}

function RoomTile({ room }: { room: Room }) {
  const history = room.history.filter((v): v is number => v !== null)
  const heating = room.climate?.action === 'heating'

  return (
    <li role="group" aria-label={room.name} className="flex min-h-0 flex-col overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 tabular-nums dark:border-slate-800 dark:bg-slate-950/70">
      <div className="min-w-0 px-2.5 pt-1.5">
        <p className="truncate text-xs text-zinc-500 dark:text-slate-400">{room.name}</p>
        <p className="flex items-baseline gap-1.5">
          <span className="text-lg leading-6 font-bold text-zinc-900 dark:text-slate-50">{room.temperature !== null ? formatTemperature(room.temperature) : '—'}</span>
          {room.humidity !== null && <span className="text-xs text-zinc-500 dark:text-slate-400">{formatNumber(Math.round(room.humidity))} %</span>}
        </p>
        {room.climate && room.climate.target !== null && (
          <p className="flex items-center gap-1 truncate text-[0.7rem] text-zinc-500 dark:text-slate-400" title={room.climate.mode === 'off' ? 'Chauffage éteint' : undefined}>
            {heating && <FlameIcon className="size-3 shrink-0 text-orange-500 dark:text-orange-400" />}
            consigne {formatNumber(room.climate.target)} °C{heating && <span className="font-medium text-orange-600 dark:text-orange-400"> · chauffe</span>}
          </p>
        )}
      </div>
      {history.length > 0 && (
        <div className="mt-auto h-8 min-h-0 shrink pt-1">
          <Sparkline
            values={history}
            minSpan={1}
            label={`${room.name}, 24 h : de ${formatTemperature(Math.min(...history))} à ${formatTemperature(Math.max(...history))}`}
            className="h-full text-sky-500 dark:text-sky-400"
          />
        </div>
      )}
    </li>
  )
}
