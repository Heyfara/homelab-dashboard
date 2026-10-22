import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { diskTone, formatUsage, MachineCard, type MachineData, type MachineProps } from './MachineCard'

const card: CardView<MachineProps> = { id: 'm', type: 'machine', title: null, span: 2, rows: 2, refresh: 60, props: { title: 'alpha', system: 'Alpha' } }

const at = ['2026-10-19T19:10:00+00:00', '2026-10-19T19:30:00+00:00']
const data: MachineData = {
  machine: { name: 'Alpha', ip: '10.0.0.2', status: 'up', uptime: 3826476, agent: '0.21.0' },
  now: { cpu: 3.39, memory: 31.74, load: [0.03, 0.13, 0.15], temperature: 42 },
  memory: { used: 4.9, total: 15.4 },
  disks: [
    { name: 'Système', percent: 32.04, used: 142.86, total: 465.88 },
    { name: 'RAID', percent: 92.1, used: 1690, total: 1832.68 },
  ],
  series: { at, cpu: [2.5, 3.53], memory: [30, 31.8], netSent: [10000, 12974], netReceived: [20000, 9631] },
}
const result = (d: MachineData): CardResult<MachineData> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-10-19T19:31:00+00:00' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('diskTone', () => {
  it.each([
    [32, 'ok'],
    [80, 'warn'],
    [92, 'down'],
  ] as const)('%d %% → %s', (percent, tone) => expect(diskTone(percent)).toBe(tone))
})

describe('formatUsage', () => {
  const clean = (s: string) => s.replace(/[\u00a0\u202f]/g, ' ')
  it.each([
    [4.9, 15.4, '4,9 / 15,4 Go'],
    [1690, 1832.68, '1,7 / 1,8 To'],
    [142.86, 1832.68, '142,9 Go / 1,8 To'],
  ])('%d / %d → %s', (used, total, expected) => expect(clean(formatUsage(used, total))).toBe(expected))
})

describe('MachineCard', () => {
  it('présente la machine : titre, IP, état, depuis quand elle tourne, température', () => {
    const { container } = render(<MachineCard card={card} result={result(data)} />)

    screen.getByRole('heading', { name: 'alpha' })
    screen.getByText('10.0.0.2')
    screen.getByText('En ligne')
    expect(text(container)).toContain('depuis 44 j')
    expect(text(container)).toContain('42 °C')
  })

  it('signale une machine hors ligne', () => {
    render(<MachineCard card={card} result={result({ ...data, machine: { ...data.machine, status: 'down' } })} />)

    screen.getByText('Hors ligne')
  })

  it('une jauge par disque, avec remplissage et taille', () => {
    render(<MachineCard card={card} result={result(data)} />)

    const system = screen.getByRole('progressbar', { name: /Système/ })
    expect(system.getAttribute('aria-valuenow')).toBe('32')
    const raid = screen.getByRole('progressbar', { name: /RAID/ })
    expect(text(raid.closest('li')!)).toContain('1,7 / 1,8 To')
    expect(raid.className).toContain('text-rose')
  })

  it('un graphique par mesure demandée, avec la valeur actuelle', () => {
    render(<MachineCard card={card} result={result(data)} />)

    expect(text(screen.getByRole('img', { name: /^Processeur/ }))).toBe('')
    expect(text(screen.getByRole('group', { name: 'Processeur' }))).toContain('3,4 %')
    expect(text(screen.getByRole('group', { name: 'Mémoire' }))).toContain('4,9 / 15,4 Go')
    const network = screen.getByRole('group', { name: 'Réseau' })
    expect(text(network)).toContain('13 Ko/s')
    expect(text(network)).toContain('9,6 Ko/s')
    expect(screen.queryByRole('group', { name: 'Température' })).toBeNull()
    // Deux séries : chaque valeur porte le trait de couleur de sa courbe.
    expect(network.querySelectorAll('[data-key="Envoyé"].text-up, [data-key="Reçu"].text-down')).toHaveLength(2)
  })

  it('annonce le chargement', () => {
    const { container } = render(<MachineCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
