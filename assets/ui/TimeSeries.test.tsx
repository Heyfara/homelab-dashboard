import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { TimeSeries } from './TimeSeries'

const at = ['2026-10-19T19:00:00+00:00', '2026-10-19T19:20:00+00:00', '2026-10-19T19:40:00+00:00']
const pct = (v: number) => `${v} %`

function hover(el: Element, ratio: number) {
  el.getBoundingClientRect = () => ({ left: 0, width: 200, top: 0, height: 40, right: 200, bottom: 40, x: 0, y: 0, toJSON: () => ({}) })
  fireEvent.pointerMove(el, { clientX: ratio * 200 })
}

describe('TimeSeries', () => {
  it('trace une ligne par série, à l’échelle du maximum donné', () => {
    const { container } = render(<TimeSeries label="CPU" at={at} max={100} series={[{ label: 'CPU', values: [0, 50, 100], className: '', format: pct }]} />)

    expect(container.querySelectorAll('polyline')).toHaveLength(1)
    expect(container.querySelector('polyline')?.getAttribute('points')).toBe('0,40 50,20 100,0')
  })

  it('remplit sous chaque courbe d’un dégradé qui s’efface vers le bas', () => {
    const { container } = render(<TimeSeries label="CPU" at={at} series={[{ label: 'CPU', values: [0, 50, 100], className: '', format: pct }]} />)

    const fill = container.querySelector('polygon')!.getAttribute('fill')!
    const id = fill.match(/^url\(#(.+)\)$/)![1]!
    const stops = Array.from(container.querySelectorAll(`[id="${id}"] stop`)).map((s) => s.getAttribute('stop-opacity'))
    expect(Number(stops[0])).toBeGreaterThan(0)
    expect(stops.at(-1)).toBe('0')
  })

  it('une mesure manquante coupe la ligne', () => {
    const { container } = render(<TimeSeries label="CPU" at={[...at, at[2]!]} series={[{ label: 'CPU', values: [1, null, 2, 3], className: '', format: pct }]} />)

    expect(container.querySelectorAll('polyline')).toHaveLength(1)
    expect(container.querySelectorAll('circle[data-isolated]')).toHaveLength(1)
  })

  it('le survol montre l’heure et la valeur de chaque série au point le plus proche', () => {
    render(
      <TimeSeries
        label="Réseau"
        at={at}
        series={[
          { label: 'Envoyé', values: [10, 20, 30], className: '', format: (v) => `${v} o/s` },
          { label: 'Reçu', values: [1, 2, 3], className: '', format: (v) => `${v} o/s` },
        ]}
      />,
    )

    const area = screen.getByRole('img', { name: /Réseau/ })
    hover(area, 0.55)

    const tooltip = screen.getByRole('tooltip')
    expect(tooltip.textContent).toContain('20 o/s')
    expect(tooltip.textContent).toContain('Envoyé')
    expect(tooltip.textContent).toContain('2 o/s')
    expect(tooltip.textContent).toMatch(/\d\d:20/)
  })

  it('la sortie du pointeur cache l’infobulle', () => {
    render(<TimeSeries label="CPU" at={at} series={[{ label: 'CPU', values: [1, 2, 3], className: '', format: pct }]} />)
    const area = screen.getByRole('img', { name: /CPU/ })

    hover(area, 0.1)
    fireEvent.pointerLeave(area)

    expect(screen.queryByRole('tooltip')).toBeNull()
  })

  it('au clavier, les flèches parcourent les mesures', () => {
    render(<TimeSeries label="CPU" at={at} series={[{ label: 'CPU', values: [1, 2, 3], className: '', format: pct }]} />)
    const area = screen.getByRole('img', { name: /CPU/ })

    fireEvent.focus(area)
    expect(screen.getByRole('tooltip').textContent).toContain('3 %')
    fireEvent.keyDown(area, { key: 'ArrowLeft' })
    expect(screen.getByRole('tooltip').textContent).toContain('2 %')
  })

  it('sans assez de mesures', () => {
    render(<TimeSeries label="CPU" at={[at[0]!]} series={[{ label: 'CPU', values: [1], className: '', format: pct }]} />)

    screen.getByText('Pas encore de mesures')
  })
})
