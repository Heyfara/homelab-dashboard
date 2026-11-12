import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Sparkline } from './Sparkline'

describe('Sparkline', () => {
  it('avec moins de deux mesures, seulement la ligne de base en pointillés', () => {
    const { container } = render(<Sparkline values={[3]} label="débit" className="" />)

    expect(container.querySelector('polyline')).toBeNull()
    expect(container.querySelector('line')?.getAttribute('stroke-dasharray')).toBe('3 3')
  })

  it('trace la courbe à l’échelle, à partir de zéro', () => {
    const { container } = render(<Sparkline values={[0, 50, 100]} label="débit" className="" />)

    const line = container.querySelector('polyline')
    expect(line?.getAttribute('points')).toBe('0,32 50,18 100,4')
  })

  it('courbe plate en bas quand il n’y a aucun transfert', () => {
    const { container } = render(<Sparkline values={[0, 0]} label="débit" className="" />)

    expect(container.querySelector('polyline')?.getAttribute('points')).toBe('0,32 100,32')
  })

  it('avec minSpan, échelle du minimum au maximum (températures)', () => {
    const { container } = render(<Sparkline values={[16, 18, 17]} minSpan={1} label="température" className="" />)

    expect(container.querySelector('polyline')?.getAttribute('points')).toBe('0,32 50,4 100,18')
  })

  it('avec minSpan, une courbe presque plate reste au milieu', () => {
    const { container } = render(<Sparkline values={[17, 17]} minSpan={2} label="température" className="" />)

    expect(container.querySelector('polyline')?.getAttribute('points')).toBe('0,18 100,18')
  })

  it('marque la dernière valeur', () => {
    const { container } = render(<Sparkline values={[0, 50, 100]} label="débit" className="" />)

    const dot = container.querySelector('[data-endpoint]') as HTMLElement
    expect(dot.style.top).toBe('12.5%')
  })

  it('se décrit pour les lecteurs d’écran', () => {
    const { container } = render(<Sparkline values={[0, 1]} label="Débit reçu, 10 dernières minutes" className="" />)

    expect(container.querySelector('[role="img"]')?.getAttribute('aria-label')).toBe('Débit reçu, 10 dernières minutes')
  })
})
