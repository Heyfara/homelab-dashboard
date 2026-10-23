import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { CardFrame } from './CardFrame'

describe('CardFrame', () => {
  // Un élément en position absolue (sr-only des lignes masquées de la carte
  // containers…) se place par rapport à son ancêtre positionné : si le cadre ne
  // l'est pas, overflow-hidden ne le coupe pas et la page défile en largeur.
  it('est le bloc de référence des éléments positionnés de la carte', () => {
    const { container } = render(<CardFrame>{null}</CardFrame>)
    const frame = container.querySelector('article')!

    expect([...frame.classList]).toEqual(expect.arrayContaining(['relative', 'overflow-hidden']))
  })
})
