import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { CraftyCard, frenchSize, type CraftyData, type CraftyProps } from './CraftyCard'

const card: CardView<CraftyProps> = { id: 'c', type: 'crafty', title: null, span: 2, rows: 2, refresh: 60, props: { title: 'Crafty', service: 'Crafty', url: 'https://crafty.example', icon: 'sh-crafty-controller' } }

const server = (name: string, state: string, extra: Partial<CraftyData['servers'][number]> = {}) => ({ id: name, name, state, online: 0, max: 0, players: [], version: null, motd: null, worldSize: '1.0GB', startedAt: null, public: false, link: `https://crafty.example/panel/server_detail?id=${name}`, icon: null, ...extra })
const data: CraftyData = {
  playersOnline: 2,
  servers: [
    server('Monde survie', 'online', { online: 2, max: 10, players: ['Steve', 'Alex'], version: 'Paper 26.2', worldSize: '423.2MB', icon: 'data:image/png;base64,AAAA' }),
    server('Public', 'online', { max: 20, public: true, version: 'Paper 26.2' }),
    server('Planté', 'crashed'),
    server('Ancien monde', 'stopped'),
  ],
}
const result = (d: CraftyData): CardResult<CraftyData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')
const rows = () => screen.getByRole('list', { name: 'Serveurs Minecraft' }).querySelectorAll(':scope > li')

describe('CraftyCard', () => {
  it('joueurs connectés au total', () => {
    render(<CraftyCard card={card} result={result(data)} />)

    expect(text(screen.getByRole('heading').parentElement!)).toContain('2 joueurs en ligne')
  })

  it('un serveur en ligne : état, joueurs et pseudos sous le nom, version au survol, icône', () => {
    render(<CraftyCard card={card} result={result(data)} />)

    const first = rows()[0]!
    expect(text(first)).toContain('Monde survie')
    expect(text(first)).toContain('En ligne')
    expect(text(first)).toContain('2/10 · Steve, Alex')
    expect(text(first)).not.toContain('Paper 26.2')
    expect(first.querySelector('a')?.getAttribute('title')).toContain('Paper 26.2 · 423,2 Mo')
    expect(first.querySelector('img')?.getAttribute('src')).toBe('data:image/png;base64,AAAA')
    expect(first.querySelector('a')?.getAttribute('href')).toBe('https://crafty.example/panel/server_detail?id=Monde survie')
  })

  it('serveur ouvert sur Internet signalé', () => {
    render(<CraftyCard card={card} result={result(data)} />)

    expect(rows()[1]!.querySelector('[title="Ouvert sur Internet"]')).not.toBeNull()
    expect(rows()[0]!.querySelector('[title="Ouvert sur Internet"]')).toBeNull()
  })

  it('serveur arrêté : version et taille du monde sous le nom', () => {
    render(<CraftyCard card={card} result={result({ ...data, servers: [server('Ancien monde', 'stopped', { version: 'Paper 26.1.2', worldSize: '2.1GB' })] })} />)

    expect(text(rows()[0]!)).toContain('Paper 26.1.2 · 2,1 Go')
  })

  it('planté et arrêté ; seul l’arrêté est grisé', () => {
    render(<CraftyCard card={card} result={result(data)} />)

    expect(rows()[2]!.querySelector('a')?.className).not.toContain('opacity')
    expect(rows()[3]!.querySelector('a')?.className).toContain('opacity')
    expect(text(rows()[2]!)).toContain('Planté')
    expect(text(rows()[3]!)).toContain('Arrêté')
    expect(text(rows()[3]!)).not.toContain('0/0')
  })

  it('aucun joueur : le dit', () => {
    render(<CraftyCard card={card} result={result({ ...data, playersOnline: 0 })} />)

    expect(text(screen.getByRole('heading').parentElement!)).toContain('personne en ligne')
  })

  it('annonce le chargement', () => {
    const { container } = render(<CraftyCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })

  it('tailles de Crafty en français', () => {
    expect(frenchSize('2.1GB').replace(/[\u00a0\u202f]/g, ' ')).toBe('2,1 Go')
    expect(frenchSize('547.4MB').replace(/[\u00a0\u202f]/g, ' ')).toBe('547,4 Mo')
    expect(frenchSize('inconnu')).toBe('inconnu')
  })
})
