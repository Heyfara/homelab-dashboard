import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { RommCard, type RommData, type RommProps } from './RommCard'

const card: CardView<RommProps> = { id: 'r', type: 'romm', title: null, span: 2, rows: 2, refresh: 900, props: { title: 'Jeux rétro', service: 'Jeux rétro', url: 'https://jeux.example', icon: 'sh-romm' } }

const data: RommData = {
  stats: { games: 28, platforms: 3, saves: 10 },
  games: [
    { id: 29, title: 'Bob le Dragon', platform: 'Amstrad CPC', cover: 'https://jeux.example/assets/romm/resources/roms/1/29/cover/small.png', link: 'https://jeux.example/rom/29', addedAt: new Date().toISOString() },
    { id: 24, title: 'Jeu Inconnu', platform: 'GX4000', cover: null, link: 'https://jeux.example/rom/24', addedAt: '2020-01-01T00:00:00+00:00' },
  ],
  platforms: [
    { name: 'Amstrad CPC', games: 21, link: 'https://jeux.example/platform/1' },
    { name: 'GX4000', games: 1, link: 'https://jeux.example/platform/4' },
  ],
}
const result = (d: RommData): CardResult<RommData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })

describe('RommCard', () => {
  it('chiffres de la bibliothèque dans l’en-tête', () => {
    render(<RommCard card={card} result={result(data)} />)

    screen.getByText('28 jeux · 3 plateformes · 10 sauvegardes')
  })

  it('derniers jeux en slider, jaquette et plateforme', () => {
    render(<RommCard card={card} result={result(data)} />)

    screen.getByRole('list', { name: 'Derniers jeux ajoutés' })
    const first = screen.getByRole('link', { name: 'Bob le Dragon (Amstrad CPC)' })
    expect(first.getAttribute('href')).toBe('https://jeux.example/rom/29')
    expect(first.querySelector('img')?.getAttribute('src')).toBe(data.games[0]!.cover)
    expect(screen.getByRole('link', { name: 'Jeu Inconnu (GX4000)' }).querySelector('img')).toBeNull()
  })

  it('« Nouveau » sur les jeux de la semaine', () => {
    render(<RommCard card={card} result={result(data)} />)

    expect(screen.getAllByText('Nouveau')).toHaveLength(1)
  })

  it('jeux par plateforme, avec lien et singulier', () => {
    render(<RommCard card={card} result={result(data)} />)

    const nav = screen.getByRole('list', { name: 'Plateformes' })
    expect(nav.textContent).toContain('Amstrad CPC21 jeux')
    expect(screen.getByRole('link', { name: 'GX4000 : 1 jeu' }).getAttribute('href')).toBe('https://jeux.example/platform/4')
  })

  it('aucun jeu : le dit', () => {
    render(<RommCard card={card} result={result({ stats: { games: 0, platforms: 0, saves: 0 }, games: [], platforms: [] })} />)

    screen.getByText('Aucun jeu pour l’instant.')
    screen.getByText('0 jeu · 0 plateforme · 0 sauvegarde')
  })

  it('annonce le chargement', () => {
    const { container } = render(<RommCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
