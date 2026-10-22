import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { SectionGrid } from './SectionGrid'

describe('SectionGrid', () => {
  it('passe le nombre de colonnes et la largeur de chaque carte en variables CSS', () => {
    const { container } = render(
      <SectionGrid columns={3} items={[{ key: 'a', span: 2, rows: 2 }, { key: 'b', span: 1, rows: 1 }]} render={(item) => <span>{item.key}</span>} />,
    )

    const grid = container.firstElementChild as HTMLElement
    expect(grid.style.getPropertyValue('--cols')).toBe('3')
    const cells = Array.from(grid.children) as HTMLElement[]
    expect(cells.map((c) => c.style.getPropertyValue('--span'))).toEqual(['2', '1'])
    expect(cells.map((c) => c.style.getPropertyValue('--rows'))).toEqual(['2', '1'])
  })

  it('cale toutes les rangées sur la même hauteur', () => {
    const { container } = render(<SectionGrid columns={2} items={[]} render={() => null} />)

    expect(container.firstElementChild?.className).toContain('auto-rows-(--card-row)')
  })

  it('ne dépasse jamais le nombre de colonnes', () => {
    const { container } = render(<SectionGrid columns={2} items={[{ key: 'a', span: 5, rows: 1 }]} render={() => null} />)

    expect((container.firstElementChild?.firstElementChild as HTMLElement).style.getPropertyValue('--span')).toBe('2')
  })
})
