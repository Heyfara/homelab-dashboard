import { describe, expect, it } from 'vitest'
import { appendSample } from './history'

describe('appendSample', () => {
  it('ajoute une mesure', () => {
    expect(appendSample([], { at: 't1', value: 5 }, 3)).toEqual([{ at: 't1', value: 5 }])
  })

  it('ignore une mesure déjà vue (réponse servie depuis le cache)', () => {
    const history = [{ at: 't1', value: 5 }]

    expect(appendSample(history, { at: 't1', value: 5 }, 3)).toBe(history)
  })

  it('ne garde que les plus récentes', () => {
    const history = [1, 2, 3].map((v) => ({ at: `t${v}`, value: v }))

    expect(appendSample(history, { at: 't4', value: 4 }, 3).map((s) => s.value)).toEqual([2, 3, 4])
  })
})
