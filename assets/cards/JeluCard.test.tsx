import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { JeluCard, type JeluData, type JeluProps } from './JeluCard'

const card: CardView<JeluProps> = { id: 'j', type: 'jelu', title: null, span: 2, rows: 2, refresh: 900, props: { title: 'Jelu', service: 'Jelu', url: 'https://lectures.example', icon: 'sh-jelu' } }

const book = (reader: string, id: string, title: string, percent: number | null = null) => ({ reader, bookId: id, title, authors: 'Auteur Fictif', cover: `https://lectures.example/files/${id}.jpg`, link: `https://lectures.example/books/${id}`, percent, date: '2026-10-10T20:00:00+00:00' })
const data: JeluData = {
  year: 2026,
  readers: [{ name: 'Tom', finished: 12 }, { name: 'Lea', finished: 1 }, { name: 'Zoe', finished: 0 }],
  reading: [book('Tom', 'b1', 'Le Phare des brumes', 40), book('Lea', 'b2', 'La Forêt qui parle'), book('Zoe', 'b9', 'Troisième')],
  finished: [book('Tom', 'b3', 'Le Dernier Tome')],
}
const result = (d: JeluData): CardResult<JeluData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('JeluCard', () => {
  it('livres de l’année par lecteur', () => {
    render(<JeluCard card={card} result={result(data)} />)

    expect(text(screen.getByRole('list', { name: 'Livres lus en 2026' }))).toBe('Tom12Lea1Zoe0')
  })

  it('en ce moment : livre, auteur, lecteur, avancement', () => {
    render(<JeluCard card={card} result={result(data)} />)

    const first = screen.getByRole('region', { name: 'En ce moment' }).querySelectorAll('a')[0]!
    expect(first.getAttribute('href')).toBe('https://lectures.example/books/b1')
    expect(text(first)).toContain('Le Phare des brumes')
    expect(text(first)).toContain('Auteur Fictif')
    expect(text(first)).toContain('Tom')
    expect(screen.getByRole('progressbar', { name: 'Le Phare des brumes : 40 %' })).toBeTruthy()
  })

  it('avancement à 0 % (rien de saisi dans Jelu) : pas de barre', () => {
    render(<JeluCard card={card} result={result({ ...data, reading: [book('Lea', 'b2', 'La Forêt qui parle', 0)] })} />)

    expect(screen.queryByRole('progressbar')).toBeNull()
  })

  it('une lecture par rangée, puis « +N »', () => {
    render(<JeluCard card={card} result={result(data)} />)

    expect(screen.getByRole('region', { name: 'En ce moment' }).querySelectorAll('a')).toHaveLength(2)
    screen.getByText('+1 autre lecture en cours')
  })

  it('terminés dans l’année en slider, lecteur en sous-titre', () => {
    render(<JeluCard card={card} result={result(data)} />)

    expect(screen.getByRole('list', { name: 'Terminés en 2026' }).querySelector('li')?.className).toContain('aspect-[2/3]')
    screen.getByRole('link', { name: 'Le Dernier Tome (Tom)' })
  })

  it('rien en cours ni terminé : le dit', () => {
    render(<JeluCard card={card} result={result({ ...data, reading: [], finished: [] })} />)

    screen.getByText('Aucune lecture en cours ni terminée cette année.')
  })

  it('annonce le chargement', () => {
    const { container } = render(<JeluCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
