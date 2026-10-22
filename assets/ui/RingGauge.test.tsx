import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { RingGauge } from './RingGauge'

describe('RingGauge', () => {
  it('remplit l’anneau en proportion', () => {
    const { container } = render(<RingGauge value={0.25} label="Niveau 37" />)

    const arc = container.querySelector('[data-arc]')!
    const length = Number(arc.getAttribute('stroke-dasharray'))
    expect(Number(arc.getAttribute('stroke-dashoffset'))).toBeCloseTo(length * 0.75)
  })

  it('borne la valeur entre 0 et 1', () => {
    const { container } = render(<RingGauge value={1.4} label="x" />)

    expect(Number(container.querySelector('[data-arc]')!.getAttribute('stroke-dashoffset'))).toBe(0)
  })

  it('peut tracer l’arc en dégradé', () => {
    const { container } = render(<RingGauge value={0.5} label="x" gradient={['#4f46e5', '#c026d3']} />)

    const arc = container.querySelector('[data-arc]')!
    const id = arc.getAttribute('stroke')!.match(/^url\(#(.+)\)$/)![1]!
    const stops = container.querySelectorAll(`[id="${id}"] stop`)
    expect(Array.from(stops).map((s) => s.getAttribute('stop-color'))).toEqual(['#4f46e5', '#c026d3'])
  })

  it('s’annonce comme une progression', () => {
    render(<RingGauge value={0.9} label="Progression du niveau 26">25</RingGauge>)

    const meter = screen.getByRole('progressbar', { name: 'Progression du niveau 26' })
    expect(meter.getAttribute('aria-valuenow')).toBe('90')
    screen.getByText('25')
  })
})
