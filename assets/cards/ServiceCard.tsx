import type { CardComponent } from './registry'
import { ServiceIcon } from '../ui/ServiceIcon'
import { StatusPill } from '../ui/StatusPill'
import { serviceStatus, type ServiceData } from './serviceStatus'
import { ExternalLink } from '../ui/ExternalLink'

/** Props de ServiceCard::describe() côté PHP. */
export type ServiceProps = {
  name: string
  description: string | null
  url: string | null
  icon: string | null
  host: string | null
}

export const ServiceCard: CardComponent<ServiceProps, ServiceData> = ({ card, result }) => {
  const { name, description, url, icon } = card.props
  const title = card.title ?? name
  const status = serviceStatus(result?.status === 'ok' ? result.data : null)

  const body = (
    <>
      <ServiceIcon icon={icon} name={title} />
      <span className="min-w-0 flex-1">
        <span className="block truncate font-semibold text-zinc-900 dark:text-slate-50">{title}</span>
        {description && <span className="block truncate text-sm text-zinc-500 dark:text-slate-400">{description}</span>}
      </span>
    </>
  )

  return (
    <div className="flex flex-col gap-3">
      {url ? (
        <ExternalLink href={url} className="-m-2 flex items-center gap-3 rounded-xl p-2 hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-slate-800">
          {body}
        </ExternalLink>
      ) : (
        <div className="flex items-center gap-3">{body}</div>
      )}
      <StatusPill tone={status.tone} label={status.label} />
    </div>
  )
}
