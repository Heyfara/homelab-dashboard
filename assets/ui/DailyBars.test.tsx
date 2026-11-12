import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { DailyBars } from './DailyBars'

const at = ['2026-11-10', '2026-11-11', '2026-11-12']
const kwh = (v: number) => `${v} kWh`
const series = [
  { label: 'Réseau', values: [8, 6, null], className: '', format: kwh },
  { label: 'Solaire', values: [2, null, 1], className: '', format: kwh },
]

function hover(el: Element, ratio: number) {
  el.getBoundingClientRect = () => ({ left: 0, width: 300, top: 0, height: 40, right: 300, bottom: 40, x: 0, y: 0, toJSON: () => ({}) })
  fireEvent.pointerMove(el, { clientX: ratio * 300 })
}

describe('DailyBars', () => {
  it('une barre par jour, segments empilés à l’échelle du plus haut total', () => {
    const { container } = render(<DailyBars label="Énergie" at={at} series={series} />)

    const bars = container.querySelectorAll('[data-bar]')
    expect(bars).toHaveLength(3)
    const heights = (bar: Element) => Array.from(bar.querySelectorAll('[data-segment]')).map((s) => (s as HTMLElement).style.height)
    expect(heights(bars[0]!)).toEqual(['80%', '20%'])
    expect(heights(bars[1]!)).toEqual(['60%'])
    expect(heights(bars[2]!)).toEqual(['10%'])
  })

  it('le survol montre le jour, chaque série et le total', () => {
    render(<DailyBars label="Énergie" at={at} series={series} formatTotal={kwh} />)

    hover(screen.getByRole('img', { name: /Énergie/ }), 0.1)

    const tooltip = screen.getByRole('tooltip')
    expect(tooltip.textContent).toContain('10 nov.')
    expect(tooltip.textContent).toContain('8 kWh')
    expect(tooltip.textContent).toContain('Réseau')
    expect(tooltip.textContent).toContain('2 kWh')
    expect(tooltip.textContent).toContain('Total')
    expect(tooltip.textContent).toContain('10 kWh')
  })

  it('au clavier, les flèches passent d’un jour à l’autre', () => {
    render(<DailyBars label="Énergie" at={at} series={series} />)
    const area = screen.getByRole('img', { name: /Énergie/ })

    fireEvent.focus(area)
    expect(screen.getByRole('tooltip').textContent).toContain('12 nov.')
    fireEvent.keyDown(area, { key: 'ArrowLeft' })
    expect(screen.getByRole('tooltip').textContent).toContain('11 nov.')
  })

  it('sans données, un message', () => {
    render(<DailyBars label="Énergie" at={[]} series={[]} />)

    expect(screen.getByText('Pas encore de mesures')).toBeTruthy()
  })
})
