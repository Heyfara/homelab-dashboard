import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { MinecraftCard, type MinecraftData, type MinecraftProps } from './MinecraftCard'

const card: CardView<MinecraftProps> = { id: 'm', type: 'minecraft', title: null, span: 2, rows: 3, refresh: 300, props: { title: 'Monde survie', icon: 'sh-crafty-controller' } }

const data: MinecraftData = {
  server: { state: 'online', online: 2, max: 10, players: ['Steve', 'Alex'], version: 'Paper 26.2', link: 'https://crafty.example/panel/server_detail?id=s1' },
  image: { url: 'https://images.example/famille.png?v=1', renderedAt: '2026-10-20T12:00:00+00:00' },
}
const result = (d: MinecraftData): CardResult<MinecraftData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('MinecraftCard', () => {
  it('rendu du monde, ouvert en grand au clic', () => {
    render(<MinecraftCard card={card} result={result(data)} />)

    const link = screen.getByRole('link', { name: 'Voir le rendu de Monde survie en grand' })
    expect(link.getAttribute('href')).toBe('https://images.example/famille.png?v=1')
    expect(link.querySelector('img')?.getAttribute('src')).toBe('https://images.example/famille.png?v=1')
  })

  it('état du serveur et joueurs connectés', () => {
    render(<MinecraftCard card={card} result={result(data)} />)

    const head = screen.getByRole('heading').parentElement!
    expect(text(head)).toContain('Monde survie')
    expect(text(head)).toContain('En ligne')
    expect(text(head)).toContain('2/10 · Steve, Alex')
  })

  it('serveur arrêté : pas de compteur de joueurs', () => {
    render(<MinecraftCard card={card} result={result({ ...data, server: { ...data.server, state: 'stopped', online: 0, max: 0, players: [] } })} />)

    const head = screen.getByRole('heading').parentElement!
    expect(text(head)).toContain('Arrêté')
    expect(text(head)).not.toContain('0/0')
  })

  it('date du rendu', () => {
    render(<MinecraftCard card={card} result={result(data)} />)

    expect(text(screen.getByText(/^Rendu du/))).toBe('Rendu du 20 oct. 2026')
  })

  it('annonce le chargement', () => {
    const { container } = render(<MinecraftCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
