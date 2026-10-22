import { render, screen } from '@testing-library/react'
import { useRef } from 'react'
import { describe, expect, it } from 'vitest'
import { countOverflowing, useOverflowCount } from './useOverflowCount'

describe('countOverflowing', () => {
  it('compte les éléments qui commencent au-delà de la largeur visible', () => {
    expect(countOverflowing(300, [0, 0, 160, 160, 320, 320, 480])).toBe(3)
  })

  it('sans mise en page (largeur nulle), rien n’est compté comme masqué', () => {
    expect(countOverflowing(0, [0, 0, 0])).toBe(0)
  })
})

describe('useOverflowCount', () => {
  function List() {
    const ref = useRef<HTMLUListElement>(null)
    const hidden = useOverflowCount(ref, 3)
    return (
      <>
        <p>masqués : {hidden}</p>
        <ul ref={ref}>
          <li>a</li>
          <li>b</li>
          <li>c</li>
        </ul>
      </>
    )
  }

  it('renvoie 0 tant que rien ne déborde', () => {
    render(<List />)

    screen.getByText('masqués : 0')
  })
})
