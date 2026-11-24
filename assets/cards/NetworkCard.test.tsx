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
  ...d,
})

const data: NetworkData = {
  devices: [
    device({}),
    device({ mac: '00:16:3E:00:00:02', name: 'imprimante', type: 'printer', ip: '192.168.1.30', expectedIp: '192.168.1.31' }),
    device({ mac: 'DA:A1:19:00:00:05', name: null, vendor: null, type: 'other', ip: '192.168.1.60', expectedIp: null, randomMac: true }),
    device({ mac: '00:16:3E:00:00:03', name: 'ancien-portable', type: 'laptop', online: false, lastSeen: new Date(Date.now() - 3 * 3600_000).toISOString(), expectedIp: null }),
  ],
}
const rates: NetworkRates = { wan: { state: 'up', media: 'ftth', down: 2_500_000, up: 40_000, maxDown: 125_000_000, maxUp: 87_500_000 } }

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
    expect(text(screen.getByRole('group', { name: 'Appareils' }))).toContain('3 en ligne sur 4')
  })

  it('débits pas encore lus : tuiles en attente, la liste reste là', () => {
    renderCard(null)

    expect(screen.getByRole('group', { name: 'Descendant' }).getAttribute('aria-busy')).toBe('true')
    expect(screen.getAllByRole('listitem')).toHaveLength(4)
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
    expect(screen.getAllByRole('listitem')).toHaveLength(3)

    const unknown = within(filters).getByRole('button', { name: /Sans nom/ })
    expect(text(unknown)).toContain('1')
    fireEvent.click(unknown)
    expect(unknown.getAttribute('aria-pressed')).toBe('true')
    expect(screen.getAllByRole('listitem')).toHaveLength(1)
  })

  it('chargement : squelette', () => {
    render(<NetworkCard card={card} result={null} feeds={{ rates: null }} />)

    expect(document.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
