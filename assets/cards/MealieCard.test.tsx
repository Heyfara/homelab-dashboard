import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { MealieCard, type MealieData, type MealieProps } from './MealieCard'

const card: CardView<MealieProps> = { id: 'm', type: 'mealie', title: null, span: 2, rows: 3, refresh: 3600, props: { title: 'Mealie', service: 'Mealie', url: 'https://mealie.example', icon: 'sh-mealie' } }

const crepes = { id: 'r1', name: 'Crêpes de froment', image: 'https://mealie.example/api/media/recipes/r1/images/min-original.webp', link: 'https://mealie.example/g/home/r/crepes-de-froment', totalTime: '1 h 45 min', rating: 5 }
const gratin = { id: 'r2', name: 'Gratin de blettes', image: null, link: 'https://mealie.example/g/home/r/gratin', totalTime: '30 minutes', rating: null }

const data: MealieData = {
  today: '2026-10-20',
  idea: crepes,
  plan: [
    { date: '2026-10-20', type: 'dinner', title: 'Gratin de blettes', recipe: gratin },
    { date: '2026-10-21', type: 'lunch', title: 'Restes', recipe: null },
  ],
  recipes: [crepes, gratin],
  total: 33,
}
const result = (d: MealieData): CardResult<MealieData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('MealieCard', () => {
  it('met en avant l’idée recette du jour', () => {
    render(<MealieCard card={card} result={result(data)} />)

    const idea = screen.getByRole('link', { name: /Idée recette du jour : Crêpes de froment/ })
    expect(idea.getAttribute('href')).toBe(crepes.link)
    expect(text(idea)).toContain('1 h 45 min')
    expect(idea.querySelector('img')?.getAttribute('src')).toBe(crepes.image)
    expect(screen.getByLabelText('Note : 5 sur 5')).toBeTruthy()
  })

  it('le planning s’affiche avec le jour et le repas', () => {
    render(<MealieCard card={card} result={result(data)} />)

    const plan = screen.getByRole('region', { name: 'Au menu' })
    expect(text(plan)).toContain("Aujourd'hui")
    expect(text(plan)).toContain('Dîner')
    expect(text(plan)).toContain('Gratin de blettes')
    expect(text(plan)).toContain('Demain')
    expect(text(plan)).toContain('Restes')
    expect(within(plan).getByRole('link', { name: /Gratin de blettes/ }).getAttribute('href')).toBe(gratin.link)
  })

  it('sans planning, pas de section « Au menu »', () => {
    render(<MealieCard card={card} result={result({ ...data, plan: [] })} />)

    expect(screen.queryByRole('region', { name: 'Au menu' })).toBeNull()
  })

  it('les recettes défilent, avec leur nombre total', () => {
    render(<MealieCard card={card} result={result(data)} />)

    expect(screen.getByRole('list', { name: 'Recettes' }).querySelectorAll('li')).toHaveLength(2)
    screen.getByText('33 recettes')
  })

  it('annonce le chargement', () => {
    const { container } = render(<MealieCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
