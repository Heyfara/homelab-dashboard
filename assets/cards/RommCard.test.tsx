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
}
const result = (d: RommData): CardResult<RommData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })

describe('RommCard', () => {
  it('chiffres de la bibliothèque en bas de la carte, plus dans l’en-tête', () => {
    render(<RommCard card={card} result={result(data)} />)

    const stats = screen.getByRole('list', { name: 'Bibliothèque' })
    expect([...stats.querySelectorAll('li')].map((li) => li.textContent)).toEqual(['28 jeux', '3 plateformes', '10 sauvegardes'])
    expect(screen.getByRole('banner').textContent).not.toContain('jeux')
    expect(screen.queryByRole('list', { name: 'Plateformes' })).toBeNull()
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

  it('aucun jeu : le dit', () => {
    render(<RommCard card={card} result={result({ stats: { games: 0, platforms: 0, saves: 0 }, games: [] })} />)

    screen.getByText('Aucun jeu pour l’instant.')
    expect(screen.getByRole('list', { name: 'Bibliothèque' }).textContent).toBe('0 jeu0 plateforme0 sauvegarde')
  })

  it('annonce le chargement', () => {
    const { container } = render(<RommCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
