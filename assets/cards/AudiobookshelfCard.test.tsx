import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { AudiobookshelfCard, type AudiobookshelfData, type AudiobookshelfProps } from './AudiobookshelfCard'

const card: CardView<AudiobookshelfProps> = { id: 'a', type: 'audiobookshelf', title: null, span: 2, rows: 2, refresh: 300, props: { title: 'Audiobookshelf', service: 'Audiobookshelf', url: 'https://abs.example', icon: 'sh-audiobookshelf' } }

const anatole = { id: 'b3', title: "3 histoires d'Anatole", author: 'Anatole Latuile', cover: 'https://abs.example/api/items/b3/cover', link: 'https://abs.example/item/b3', progress: 0.73, remaining: 1106, lastPlayed: '2026-10-12T19:54:44+00:00' }
const verne = { id: 'b2', title: 'Vingt mille lieues sous les mers', author: 'Jules Verne', cover: 'https://abs.example/api/items/b2/cover', link: 'https://abs.example/item/b2', progress: 0.0055, remaining: 53789, lastPlayed: '2026-09-30T00:00:00+00:00' }
const data: AudiobookshelfData = {
  listening: [anatole, verne, { ...verne, id: 'b9', title: 'Troisième' }],
  recent: [{ id: 'b1', title: 'Astérix', author: 'Dominique Pinon', cover: 'https://abs.example/api/items/b1/cover', link: 'https://abs.example/item/b1', duration: 5984 }],
  total: 4,
}
const result = (d: AudiobookshelfData): CardResult<AudiobookshelfData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('AudiobookshelfCard', () => {
  it('« Reprendre l’écoute » : couverture, auteur, progression, temps restant', () => {
    render(<AudiobookshelfCard card={card} result={result(data)} />)

    const section = screen.getByRole('region', { name: "Reprendre l'écoute" })
    const first = section.querySelectorAll('a')[0]!
    expect(first.getAttribute('href')).toBe(anatole.link)
    expect(text(first)).toContain("3 histoires d'Anatole")
    expect(text(first)).toContain('Anatole Latuile')
    expect(text(first)).toContain('73 %')
    expect(text(first)).toContain('reste 18 min')
    expect(first.querySelector('img')?.getAttribute('src')).toBe(anatole.cover)
  })

  it('autant d’écoutes que de rangées, puis « +N »', () => {
    render(<AudiobookshelfCard card={card} result={result(data)} />)

    expect(screen.getByRole('region', { name: "Reprendre l'écoute" }).querySelectorAll('a')).toHaveLength(2)
    screen.getByText('+1 autre écoute en cours')
  })

  it('rien en cours : pas de section', () => {
    render(<AudiobookshelfCard card={card} result={result({ ...data, listening: [] })} />)

    expect(screen.queryByRole('region', { name: "Reprendre l'écoute" })).toBeNull()
  })

  it('derniers ajouts en slider, couvertures carrées, nombre total', () => {
    render(<AudiobookshelfCard card={card} result={result(data)} />)

    const list = screen.getByRole('list', { name: 'Derniers livres audio' })
    expect(list.querySelector('li')?.className).toContain('aspect-square')
    screen.getByText('4 livres')
  })

  it('annonce le chargement', () => {
    const { container } = render(<AudiobookshelfCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
