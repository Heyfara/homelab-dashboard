import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { containerStatus, ContainersCard, type ContainersData, type ContainersProps } from './ContainersCard'

const card: CardView<ContainersProps> = { id: 'c', type: 'containers', title: null, span: 2, rows: 2, refresh: 30, props: { title: 'alpha', system: 'Alpha' } }

const data: ContainersData = {
  containers: [
    { name: 'notes', status: 'stopped', health: null, uptime: null, cpu: null, memory: null },
    { name: 'jellyfin', status: 'running', health: 'unhealthy', uptime: '2 j', cpu: 4, memory: 900 },
    { name: 'immich_server', status: 'running', health: 'healthy', uptime: '7 j', cpu: 3.2, memory: 512 },
    { name: 'redis', status: 'running', health: null, uptime: '3 h', cpu: 0.1, memory: 12.4 },
  ],
  counts: { total: 4, running: 3, problems: 2 },
}
const result = (d: ContainersData): CardResult<ContainersData> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-10-20T10:00:00+00:00' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('containerStatus', () => {
  it.each([
    ['running', null, 'ok', 'En marche'],
    ['running', 'healthy', 'ok', 'Sain'],
    ['running', 'starting', 'warn', 'Démarre'],
    ['running', 'unhealthy', 'down', 'En difficulté'],
    ['restarting', null, 'warn', 'Redémarre'],
    ['paused', null, 'warn', 'En pause'],
    ['stopped', null, 'down', 'Arrêté'],
  ] as const)('%s / %s → %s %s', (status, health, tone, label) => {
    expect(containerStatus(status, health)).toEqual({ tone, label })
  })
})

describe('ContainersCard', () => {
  it('en-tête : machine, nombre de containers et de problèmes', () => {
    render(<ContainersCard card={card} result={result(data)} />)

    screen.getByRole('heading', { name: 'alpha' })
    screen.getByText('4 containers')
    screen.getByText('2 problèmes')
  })

  it('tout va bien', () => {
    render(<ContainersCard card={card} result={result({ ...data, counts: { total: 4, running: 4, problems: 0 } })} />)

    screen.getByText('Tout fonctionne')
  })

  it('une ligne par container, dans l’ordre reçu (problèmes d’abord)', () => {
    render(<ContainersCard card={card} result={result(data)} />)

    const rows = screen.getAllByRole('listitem')
    expect(rows.map((r) => r.querySelector('[data-name]')?.textContent)).toEqual(['notes', 'jellyfin', 'immich_server', 'redis'])
    expect(text(rows[0]!)).toContain('Arrêté')
    expect(text(rows[1]!)).toContain('En difficulté')
  })

  it('un container qui va bien montre depuis quand il tourne et sa mémoire', () => {
    render(<ContainersCard card={card} result={result(data)} />)

    const row = screen.getAllByRole('listitem')[2]!
    expect(text(row)).toContain('7 j')
    expect(text(row)).toContain('512 Mo')
    expect(row.querySelector('[data-dot]')?.className).toContain('bg-emerald')
  })

  it('une mémoire à 0 (non mesurée, ex. Raspberry Pi) n’est pas affichée', () => {
    const zero = { name: 'mealie', status: 'running', health: null, uptime: '8 j', cpu: 0, memory: 0 }
    render(<ContainersCard card={card} result={result({ containers: [zero], counts: { total: 1, running: 1, problems: 0 } })} />)

    expect(text(screen.getByRole('listitem'))).not.toContain('Mo')
  })

  it('aucun container', () => {
    render(<ContainersCard card={card} result={result({ containers: [], counts: { total: 0, running: 0, problems: 0 } })} />)

    screen.getByText('Aucun container')
  })

  it('annonce le chargement', () => {
    const { container } = render(<ContainersCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
