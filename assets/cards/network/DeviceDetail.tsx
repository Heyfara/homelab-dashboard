import { Fragment, type ReactNode } from 'react'
import { formatRelative, formatSpeed } from '../../ui/format'
import type { LinkRate, NetworkData, NetworkRates } from '../NetworkCard'
import { DeviceIcon, type IconKind } from './DeviceIcon'
import { deviceLabel, ipMismatch, placeLabel, TYPE_LABELS, UNCERTAIN, weakSignal } from './devices'
import { pathOf, type RateRef } from './layout'
import { rateOf } from './TopologyDiagram'

type Props = { selected: string | null; data: NetworkData; rates: NetworkRates | null }

/** Panneau de détail du nœud choisi sur le schéma ou dans la liste. */
export function DeviceDetail({ selected, data, rates }: Props) {
  return (
    <aside aria-label="Détail" className="flex flex-col gap-3 rounded-xl bg-zinc-50 p-4 text-sm dark:bg-slate-800/60">
      {selected === null ? <p className="text-zinc-500 dark:text-slate-400">Choisir un appareil sur le schéma ou dans la liste.</p> : <Content selected={selected} data={data} rates={rates} />}
    </aside>
  )
}

function Content({ selected, data, rates }: { selected: string; data: NetworkData; rates: NetworkRates | null }) {
  const path = pathOf(data, selected)

  if (selected === 'internet' || selected === 'box' || selected === 'switch') {
    const wan = rates?.wan
    const facts: Record<typeof selected, [string, string, IconKind]> = {
      internet: ['Internet', wan ? `${wan.state === 'up' ? 'Connectée' : wan.state}${wan.media ? ` · ${wan.media === 'ftth' ? 'fibre' : wan.media}` : ''}` : 'Accès Internet', 'internet'],
      box: ['Freebox', 'Routeur et serveur DHCP', 'box'],
      switch: ['Switch', `Switch de la zone ${data.wiredZone}`, 'switch'],
    }
    const [title, kind, icon] = facts[selected]
    return (
      <>
        <Head title={title} kind={kind} icon={icon} />
        <Path path={path} />
        {selected !== 'switch' && <Rates rate={wan ? { down: wan.down, up: wan.up } : undefined} />}
      </>
    )
  }

  const d = data.devices.find((x) => x.mac === selected)
  const ap = data.accessPoints.find((a) => a.device === selected || `ap:${a.id}` === selected)
  if (d === undefined) return <Head title={ap?.id ?? '?'} kind="Point d’accès" icon="network" />

  const p = d.place
  const ref: RateRef | null = ap !== undefined ? { kind: 'port', ap: ap.id, port: ap.uplink } : p?.ap && p.link === 'cable' && p.port ? { kind: 'port', ap: p.ap, port: p.port } : null
  const status = !d.online ? 'Hors ligne' : p?.seenAt ? `Vu ${formatRelative(p.seenAt)}` : 'En ligne'

  return (
    <>
      <Head title={deviceLabel(d)} kind={ap !== undefined ? `Point d’accès · ${ap.zone}` : TYPE_LABELS[d.type]} icon={d.type} badge={status} />
      {path.length > 0 ? <Path path={path} /> : <p className="text-zinc-500 dark:text-slate-400">{d.online ? 'Chemin inconnu : aucun point d’accès lu ne le voit.' : 'Hors ligne.'}</p>}
      {ap?.error && <p className="text-amber-800 dark:text-amber-200">{ap.error}</p>}
      {ref !== null && <Rates rate={rateOf(rates, ref)} />}
      <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5">
        <Fact label="IP">
          <span className="font-mono">{d.ip ?? '—'}</span>
        </Fact>
        <Fact label="MAC">
          <span className="font-mono">{d.mac}</span>
          {d.randomMac && <span className="text-zinc-500 dark:text-slate-400"> · adresse privée</span>}
        </Fact>
        {d.vendor !== null && <Fact label="Fabricant">{d.vendor}</Fact>}
        <Fact label="Connexion">
          {p === null ? (d.online ? UNCERTAIN : '—') : placeLabel(p)}
          {p?.signal != null && <span className={weakSignal(p) ? 'text-amber-700 dark:text-amber-300' : ''}> · {p.signal} dBm</span>}
        </Fact>
        <Fact label="Vu">{d.online ? 'maintenant' : d.lastSeen !== null ? formatRelative(d.lastSeen) : '—'}</Fact>
        <Fact label="IP attendue">
          {d.expectedIp === null ? 'aucun bail statique' : <span className={ipMismatch(d) ? 'font-mono text-amber-700 dark:text-amber-300' : 'font-mono'}>{d.expectedIp}</span>}
        </Fact>
      </dl>
    </>
  )
}

function Head({ title, kind, icon, badge }: { title: string; kind: string; icon: IconKind; badge?: string }) {
  return (
    <div className="flex items-center gap-3">
      <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-zinc-200/70 text-zinc-700 dark:bg-slate-700/70 dark:text-slate-200">
        <DeviceIcon kind={icon} className="size-6" />
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-base font-semibold text-zinc-900 dark:text-slate-50">{title}</p>
        <p className="truncate text-xs text-zinc-500 dark:text-slate-400">{kind}</p>
      </div>
      {badge !== undefined && <span className="shrink-0 rounded-full bg-zinc-200/70 px-2.5 py-0.5 text-xs font-medium dark:bg-slate-700/70">{badge}</span>}
    </div>
  )
}

function Path({ path }: { path: string[] }) {
  return (
    <p className="flex flex-wrap items-center gap-1 text-xs">
      {path.map((step, i) => (
        <Fragment key={i}>
          {i > 0 && <span aria-hidden="true" className="text-zinc-400">›</span>}
          <span className="rounded-md bg-white px-1.5 py-0.5 ring-1 ring-zinc-200 dark:bg-slate-900 dark:ring-slate-700">{step}</span>
        </Fragment>
      ))}
    </p>
  )
}

function Rates({ rate }: { rate: LinkRate | undefined }) {
  return (
    <div className="grid grid-cols-2 gap-2">
      <div className="rounded-lg bg-white p-2 ring-1 ring-zinc-200 dark:bg-slate-900 dark:ring-slate-700">
        <p className="text-xs text-zinc-500 dark:text-slate-400">Descendant</p>
        <p className="font-mono text-down tabular-nums">{rate ? formatSpeed(rate.down) : '…'}</p>
      </div>
      <div className="rounded-lg bg-white p-2 ring-1 ring-zinc-200 dark:bg-slate-900 dark:ring-slate-700">
        <p className="text-xs text-zinc-500 dark:text-slate-400">Montant</p>
        <p className="font-mono text-up tabular-nums">{rate ? formatSpeed(rate.up) : '…'}</p>
      </div>
    </div>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-zinc-500 dark:text-slate-400">{label}</dt>
      <dd className="min-w-0 truncate">{children}</dd>
    </>
  )
}
