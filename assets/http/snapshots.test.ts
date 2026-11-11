import { beforeEach, describe, expect, it } from 'vitest'
import type { CardResult } from './types'
import { snapshots } from './snapshots'

const ok: CardResult = { status: 'ok', data: { n: 1 }, error: null, fetchedAt: '2026-10-18T10:00:00+00:00', stale: false }

describe('snapshots', () => {
  beforeEach(() => localStorage.clear())

  it('garde le dernier résultat d’une carte', () => {
    expect(snapshots.card('tom-weather-abc')).toBeNull()
    snapshots.saveCard('tom-weather-abc', ok)
    expect(snapshots.card('tom-weather-abc')).toEqual(ok)
  })

  it('ne garde pas les erreurs', () => {
    snapshots.saveCard('tom-weather-abc', ok)
    snapshots.saveCard('tom-weather-abc', { status: 'error', data: null, error: 'boum', fetchedAt: ok.fetchedAt })
    expect(snapshots.card('tom-weather-abc')).toEqual(ok)
  })

  it('garde la mise en page d’un profil', () => {
    const view = { profile: { slug: 'tom', name: 'Tom', color: 'indigo' }, sections: [] } as never
    snapshots.saveDashboard('tom', view)
    expect(snapshots.dashboard('tom')).toEqual(view)
    expect(snapshots.dashboard('lea')).toBeNull()
  })

  it('oublie les cartes du profil qui n’existent plus, pas celles des autres profils', () => {
    snapshots.saveCard('tom-a-1', ok)
    snapshots.saveCard('tom-b-2', ok)
    snapshots.saveCard('tom-b-2?feed=live', ok)
    snapshots.saveCard('lea-a-1', ok)

    snapshots.prune('tom', ['tom-b-2'])

    expect(snapshots.card('tom-a-1')).toBeNull()
    expect(snapshots.card('tom-b-2')).toEqual(ok)
    expect(snapshots.card('tom-b-2?feed=live')).toEqual(ok)
    expect(snapshots.card('lea-a-1')).toEqual(ok)
  })

  it('ignore un contenu illisible', () => {
    localStorage.setItem('dashboard.card.tom-a-1', '{pas du json')
    expect(snapshots.card('tom-a-1')).toBeNull()
  })
})
