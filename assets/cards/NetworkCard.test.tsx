import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { NetworkCard, type NetworkData, type NetworkProps, type NetworkRates } from './NetworkCard'
import type { Device } from './network/devices'

const card: CardView<NetworkProps> = { id: 'reseau', type: 'network', title: null, span: 4, rows: 1, refresh: 30, feeds: { rates: 5 }, props: { title: 'Réseau' } }

const device = (d: Partial<Device>): Device => ({
  mac: '00:16:3E:00:00:01',
  name: 'alpha',
  vendor: 'Fabricant',
  type: 'server',
  ip: '192.168.1.2',
  expectedIp: '192.168.1.2',
  online: true,
  lastSeen: new Date().toISOString(),
  randomMac: false,
  place: { zone: 'Baie', ap: null, link: 'cable', port: null, band: null, signal: null, seenAt: null },
  ...d,
})

const data: NetworkData = {
  devices: [
    device({}),
    device({ mac: '00:16:3E:00:00:02', name: 'imprimante', type: 'printer', ip: '192.168.1.30', expectedIp: '192.168.1.31' }),
    device({ mac: 'DA:A1:19:00:00:05', name: null, vendor: null, type: 'other', ip: '192.168.1.60', expectedIp: null, randomMac: true, place: null }),
    device({ mac: '00:16:3E:00:00:03', name: 'ancien-portable', type: 'laptop', online: false, lastSeen: new Date(Date.now() - 3 * 3600_000).toISOString(), expectedIp: null, place: null }),
    device({ mac: '00:16:3E:00:00:06', name: 'pc-bureau', type: 'computer', ip: '192.168.1.70', expectedIp: null, place: { zone: 'Bureau', ap: 'flint', link: 'cable', port: 'lan2', band: null, signal: null, seenAt: null } }),
    device({ mac: '00:16:3E:00:00:07', name: 'tablette', type: 'tablet', ip: '192.168.1.71', expectedIp: null, place: { zone: 'Bureau', ap: 'flint', link: 'wifi', port: 'rax0', band: '5', signal: -80, seenAt: new Date(Date.now() - 120_000).toISOString() } }),
  ],
  accessPoints: [{ id: 'flint', zone: 'Bureau', error: null, uplink: 'eth1', radios: { ra0: '2.4', rax0: '5' }, device: null, kind: 'openwrt' }],
  wiredZone: 'Baie',
}
const rates: NetworkRates = {
  wan: { state: 'up', media: 'ftth', down: 2_500_000, up: 40_000, maxDown: 125_000_000, maxUp: 87_500_000 },
  accessPoints: { flint: { error: null, ports: { eth1: { down: 3_000_000, up: 100_000 }, lan2: { down: 1_500_000, up: 20_000 }, ra0: { down: 0, up: 0 }, rax0: { down: 4_000, up: 1_000 }, lan4: { down: 0, up: 0 } } } },
}

const ok = <T,>(d: T): CardResult<T> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-11-24T12:00:00+00:00' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')
const renderCard = (r: NetworkRates | null = rates) => render(<NetworkCard card={card} result={ok(data)} feeds={{ rates: r === null ? null : ok(r) }} />)

describe('NetworkCard', () => {
  it('tuiles : connexion, débits avec le maximum de la ligne, appareils en ligne', () => {
    renderCard()

    expect(text(screen.getByRole('group', { name: 'Internet' }))).toContain('Connectée · Fibre')
    const down = text(screen.getByRole('group', { name: 'Descendant' }))
    expect(down).toContain('2,5 Mo/s')
    expect(down).toContain('max. 1 Gb/s')
    expect(text(screen.getByRole('group', { name: 'Montant' }))).toContain('max. 700 Mb/s')
    expect(text(screen.getByRole('group', { name: 'Appareils' }))).toContain('5 en ligne sur 6')
  })

  it('débits pas encore lus : tuiles en attente, la liste reste là', () => {
    renderCard(null)

    expect(screen.getByRole('group', { name: 'Descendant' }).getAttribute('aria-busy')).toBe('true')
    expect(screen.getAllByRole('listitem')).toHaveLength(6)
  })

  it('signale les IP qui ne suivent pas leur bail et les appareils sans nom', () => {
    renderCard()

    const alerts = text(screen.getByRole('region', { name: 'À vérifier' }))
    expect(alerts).toContain('imprimante a l’adresse 192.168.1.30 au lieu de 192.168.1.31 (bail statique)')
    expect(alerts).toContain('1 appareil sans nom')
  })

  it('liste : nom, type, IP, MAC et état', () => {
    renderCard()

    const rows = screen.getAllByRole('listitem')
    expect(text(rows[0]!)).toContain('alpha')
    expect(text(rows[0]!)).toContain('Serveur')
    expect(text(rows[0]!)).toContain('192.168.1.2')
    expect(text(rows[0]!)).toContain('00:16:3E:00:00:01')
    expect(text(rows[0]!)).toContain('En ligne')
    expect(text(rows[2]!)).toContain('Adresse privée')
    expect(text(rows[3]!)).toContain('il y a 3 heures')
  })

  it('filtres avec leur nombre', () => {
    renderCard()

    const filters = screen.getByRole('group', { name: 'Filtrer les appareils' })
    fireEvent.click(within(filters).getByRole('button', { name: /En ligne/ }))
    expect(screen.getAllByRole('listitem')).toHaveLength(5)

    const unknown = within(filters).getByRole('button', { name: /Sans nom/ })
    expect(text(unknown)).toContain('1')
    fireEvent.click(unknown)
    expect(unknown.getAttribute('aria-pressed')).toBe('true')
    expect(screen.getAllByRole('listitem')).toHaveLength(1)
  })

  it('liste : position, signal et position gardée', () => {
    renderCard()

    const row = (name: string) => text(screen.getAllByRole('listitem').find((li) => text(li).includes(name))!)
    expect(row('pc-bureau')).toContain('Bureau · câble lan2')
    expect(row('alpha')).toContain('Baie · câble')
    expect(row('tablette')).toContain('Bureau · Wi-Fi 5 GHz')
    expect(row('tablette')).toContain('-80 dBm')
    expect(row('tablette')).toContain('il y a 2 minutes')
    expect(row('Adresse privée')).toContain('Position incertaine')
  })

  it('filtre par position', () => {
    renderCard()

    const zones = screen.getByRole('group', { name: 'Filtrer par position' })
    expect(text(within(zones).getByRole('button', { name: /Bureau/ }))).toContain('2')
    fireEvent.click(within(zones).getByRole('button', { name: /Baie/ }))
    expect(screen.getAllByRole('listitem')).toHaveLength(2)
    fireEvent.click(within(zones).getByRole('button', { name: /Toutes/ }))
    expect(screen.getAllByRole('listitem')).toHaveLength(6)
  })

  it('points d’accès : amont, radios et ports utilisés, avec leurs débits', () => {
    renderCard()

    const ap = within(screen.getByRole('region', { name: 'Points d’accès' }))
    const line = (label: RegExp) => text(ap.getByRole('group', { name: label }))
    expect(line(/Vers le switch/)).toContain('3 Mo/s')
    expect(line(/Wi-Fi 5 GHz/)).toContain('1 appareil')
    expect(line(/Câble lan2/)).toContain('pc-bureau')
    expect(line(/Câble lan2/)).toContain('1,5 Mo/s')
    expect(ap.queryByRole('group', { name: /lan4/ })).toBeNull()
  })

  it('point d’accès TP-Link : ses radios, sans lien mesuré vers le switch', () => {
    const archer = { id: 'archer', zone: 'Étage', error: null, uplink: 'eth1', radios: { wlan2g: '2.4', wlan5g: '5' }, device: null, kind: 'tplink' as const }
    const devices = [...data.devices, device({ mac: '00:16:3E:00:00:08', name: 'enceinte', place: { zone: 'Étage', ap: 'archer', link: 'wifi', port: 'wlan2g', band: '2.4', signal: null, seenAt: null } })]
    render(<NetworkCard card={card} result={ok({ ...data, devices, accessPoints: [...data.accessPoints, archer] })} feeds={{ rates: ok(rates) }} />)

    const panels = within(screen.getByRole('region', { name: 'Points d’accès' }))
    expect(panels.getAllByRole('group', { name: /Vers le switch/ })).toHaveLength(1)
    expect(panels.getAllByRole('group', { name: 'Wi-Fi 2,4 GHz' }).map(text).some((t) => t.includes('1 appareil'))).toBe(true)
  })

  it('point d’accès injoignable : signalé', () => {
    render(<NetworkCard card={card} result={ok({ ...data, accessPoints: [{ ...data.accessPoints[0]!, error: '192.168.1.175 injoignable en SSH : délai dépassé' }] })} feeds={{ rates: ok(rates) }} />)

    expect(text(screen.getByRole('region', { name: 'Points d’accès' }))).toContain('injoignable en SSH')
  })

  it('schéma : le cœur et chaque appareil en ligne', () => {
    renderCard()

    const diagram = within(screen.getByRole('region', { name: 'Schéma du réseau' }))
    expect(diagram.getByRole('button', { name: 'Internet' })).toBeTruthy()
    expect(diagram.getByRole('button', { name: 'pc-bureau, 192.168.1.70' })).toBeTruthy()
    expect(diagram.getByRole('button', { name: 'flint' })).toBeTruthy()
    expect(diagram.queryByRole('button', { name: /ancien-portable/ })).toBeNull()
  })

  it('clic sur un nœud : son détail, avec son chemin', () => {
    renderCard()

    fireEvent.click(within(screen.getByRole('region', { name: 'Schéma du réseau' })).getByRole('button', { name: /^tablette/ }))
    const detail = text(screen.getByRole('complementary', { name: 'Détail' }))
    expect(detail).toContain('Tablette')
    expect(detail).toContain('192.168.1.71')
    expect(detail).toContain('Bureau · Wi-Fi 5 GHz')
    expect(detail).toContain('Internet›Freebox›Switch›flint›tablette')
  })

  it('clic dans la liste : même détail', () => {
    renderCard()

    fireEvent.click(screen.getByRole('button', { name: 'Détail de imprimante' }))
    const detail = text(screen.getByRole('complementary', { name: 'Détail' }))
    expect(detail).toContain('192.168.1.31')
    expect(detail).toContain('00:16:3E:00:00:02')
  })

  it('détail d’Internet : état et débits', () => {
    renderCard()

    fireEvent.click(screen.getByRole('button', { name: 'Internet' }))
    expect(text(screen.getByRole('complementary', { name: 'Détail' }))).toContain('2,5 Mo/s')
  })

  it('survol : ce qui n’est pas sur le chemin s’estompe', () => {
    renderCard()

    const diagram = within(screen.getByRole('region', { name: 'Schéma du réseau' }))
    fireEvent.mouseEnter(diagram.getByRole('button', { name: /^pc-bureau/ }))
    expect(diagram.getByRole('button', { name: /^alpha/ }).dataset.dim).toBe('true')
    expect(diagram.getByRole('button', { name: 'Internet' }).dataset.dim).toBe('false')
  })

  it('points animés sur les liens mesurés seulement', () => {
    const { container } = renderCard()

    expect(container.querySelector('[data-link="internet>box"] .net-flow')).not.toBeNull()
    expect(container.querySelector('[data-link="ap:flint>00:16:3E:00:00:06"] .net-flow')).not.toBeNull()
    expect(container.querySelector('[data-link="ap:flint>00:16:3E:00:00:07"] .net-flow')).toBeNull()
  })

  it('chargement : squelette', () => {
    render(<NetworkCard card={card} result={null} feeds={{ rates: null }} />)

    expect(document.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
