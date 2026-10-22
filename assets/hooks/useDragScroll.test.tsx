import { fireEvent, render, screen } from '@testing-library/react'
import { useRef } from 'react'
import { describe, expect, it } from 'vitest'
import { useDragScroll } from './useDragScroll'

function List() {
  const ref = useRef<HTMLUListElement>(null)
  const drag = useDragScroll(ref)
  return (
    <ul ref={ref} aria-label="liste" {...drag}>
      <li>
        <a href="#film">Film</a>
      </li>
    </ul>
  )
}

function setup() {
  render(<List />)
  const list = screen.getByRole('list', { name: 'liste' })
  Object.defineProperty(list, 'scrollLeft', { value: 100, writable: true, configurable: true })
  return list
}

describe('useDragScroll', () => {
  it('à la souris, glisser fait défiler la liste', () => {
    const list = setup()

    fireEvent.pointerDown(list, { pointerType: 'mouse', button: 0, clientX: 300 })
    fireEvent.pointerMove(list, { pointerType: 'mouse', clientX: 220 })

    expect(list.scrollLeft).toBe(180)
    expect(list.dataset.dragging).toBe('true')
  })

  it('un glissement n’ouvre pas le lien sur lequel on relâche', () => {
    const list = setup()

    fireEvent.pointerDown(list, { pointerType: 'mouse', button: 0, clientX: 300 })
    fireEvent.pointerMove(list, { pointerType: 'mouse', clientX: 200 })
    fireEvent.pointerUp(list, { pointerType: 'mouse', clientX: 200 })

    expect(fireEvent.click(screen.getByRole('link', { name: 'Film' }))).toBe(false)
    expect(list.dataset.dragging).toBeUndefined()
  })

  it('un simple clic (moins de 5 px) ouvre le lien', () => {
    const list = setup()

    fireEvent.pointerDown(list, { pointerType: 'mouse', button: 0, clientX: 300 })
    fireEvent.pointerMove(list, { pointerType: 'mouse', clientX: 297 })
    fireEvent.pointerUp(list, { pointerType: 'mouse', clientX: 297 })

    expect(fireEvent.click(screen.getByRole('link', { name: 'Film' }))).toBe(true)
    expect(list.scrollLeft).toBe(100)
  })

  it('au doigt, le défilement natif du navigateur est laissé tel quel', () => {
    const list = setup()

    fireEvent.pointerDown(list, { pointerType: 'touch', button: 0, clientX: 300 })
    fireEvent.pointerMove(list, { pointerType: 'touch', clientX: 200 })

    expect(list.scrollLeft).toBe(100)
  })
})
