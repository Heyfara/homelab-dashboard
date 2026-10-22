import { describe, expect, it } from 'vitest'
import { moveBy, placeNextTo } from './reorder'

describe('moveBy', () => {
  it('avance ou recule une carte d’une place', () => {
    expect(moveBy(['a', 'b', 'c'], 'a', 1)).toEqual(['b', 'a', 'c'])
    expect(moveBy(['a', 'b', 'c'], 'c', -1)).toEqual(['a', 'c', 'b'])
  })

  it('ne sort pas de la section', () => {
    expect(moveBy(['a', 'b'], 'a', -1)).toEqual(['a', 'b'])
    expect(moveBy(['a', 'b'], 'b', 1)).toEqual(['a', 'b'])
    expect(moveBy(['a', 'b'], 'z', 1)).toEqual(['a', 'b'])
  })
})

describe('placeNextTo', () => {
  it('pose la carte avant ou après la carte survolée', () => {
    expect(placeNextTo(['a', 'b', 'c', 'd'], 'a', 'c', 'after')).toEqual(['b', 'c', 'a', 'd'])
    expect(placeNextTo(['a', 'b', 'c', 'd'], 'a', 'c', 'before')).toEqual(['b', 'a', 'c', 'd'])
    expect(placeNextTo(['a', 'b', 'c', 'd'], 'd', 'a', 'before')).toEqual(['d', 'a', 'b', 'c'])
  })

  it('renvoie le même tableau si rien ne change (pas de rendu inutile pendant le glisser)', () => {
    const ids = ['a', 'b', 'c']

    expect(placeNextTo(ids, 'a', 'b', 'before')).toBe(ids)
    expect(placeNextTo(ids, 'a', 'a', 'after')).toBe(ids)
    expect(placeNextTo(ids, 'a', 'z', 'after')).toBe(ids)
  })
})
