import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { ServiceIcon } from '../ui/ServiceIcon'
import { toneDot, type Tone } from '../ui/StatusPill'
import { serviceStatus, type ServiceData } from './serviceStatus'

/** Props de ServicesCard::describe() côté PHP. */
export type ServicesProps = {
  services: { id: string; name: string; description: string | null; url: string | null; icon: string | null }[]
}

/** Données de ServicesCard::fetch() côté PHP : l'état de chaque service, par identifiant. */
export type ServicesData = { status: Record<string, ServiceData> }

/** Rang d'affichage : les services en panne d'abord, puis ceux qui démarrent ou redémarrent. */
const rank: Record<Tone, number> = { down: 0, warn: 1, unknown: 2, ok: 2 }

/** Lanceur : une grille d'icônes, chacune avec une pastille d'état. */
export const ServicesCard: CardComponent<ServicesProps, ServicesData> = ({ card, result }) => {
  const data = result?.status === 'ok' ? result.data : null
  const items = card.props.services.map((service, index) => ({ ...service, index, state: serviceStatus(data ? (data.status[service.id] ?? null) : null) }))
  const shown = data ? [...items].sort((a, b) => rank[a.state.tone] - rank[b.state.tone] || a.index - b.index) : items
  const problems = items.filter((i) => i.state.tone === 'down' || i.state.tone === 'warn').length

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <h3 className="truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? 'Services'}</h3>
        <span className="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 tabular-nums dark:bg-slate-800 dark:text-slate-200">
          {items.length} services
        </span>
        {data &&
          (problems > 0 ? (
            <span className="shrink-0 rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-800 tabular-nums dark:bg-rose-400/15 dark:text-rose-300">
              {problems} {problems > 1 ? 'problèmes' : 'problème'}
            </span>
          ) : (
            <span className="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-300">Tout fonctionne</span>
          ))}
      </header>

      <ul className="grid min-h-0 flex-1 auto-rows-min grid-cols-[repeat(auto-fill,minmax(4.5rem,1fr))] gap-x-2 gap-y-2 overflow-hidden">
        {shown.map((service) => {
          const label = `${service.name} : ${service.state.label.toLowerCase()}`
          const body = (
            <>
              <span className="relative">
                <ServiceIcon icon={service.icon} name={service.name} />
                <span className={`absolute -top-1 -right-1 size-3 rounded-full ring-2 ring-white dark:ring-slate-900 ${toneDot[service.state.tone]}`} aria-hidden="true" />
              </span>
              <span className={`w-full truncate text-center text-xs ${service.state.tone === 'down' ? 'font-semibold text-rose-700 dark:text-rose-300' : 'text-zinc-700 dark:text-slate-300'}`}>
                {service.name}
              </span>
            </>
          )
          const className = 'flex flex-col items-center gap-1.5 rounded-xl p-1.5'

          return (
            <li key={service.id}>
              {service.url ? (
                <ExternalLink
                  href={service.url}
                  title={label}
                  aria-label={label}
                  className={`${className} hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-slate-800`}
                >
                  {body}
                </ExternalLink>
              ) : (
                <div title={label} className={className}>
                  {body}
                </div>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
