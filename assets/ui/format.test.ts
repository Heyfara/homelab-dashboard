import { describe, expect, it } from 'vitest'
import { formatBytes, formatDuration, formatRelative, formatSpeed } from './format'

// Espace insécable (U+00A0) et fine insécable (U+202F) d'Intl en français.
const clean = (s: string) => s.replace(/[  ]/g, ' ')

describe('formatBytes', () => {
  it.each([
    [0, '0 o'],
    [512, '512 o'],
    [1_500, '1,5 Ko'],
    [4_000_000_000, '4 Go'],
    [5_000_000_000_000, '5 To'],
    [12_345_678_901_234, '12,3 To'],
  ])('%d octets → %s', (bytes, expected) => {
    expect(clean(formatBytes(bytes))).toBe(expected)
  })
})

describe('formatSpeed', () => {
  it('ajoute /s', () => expect(clean(formatSpeed(1_500_000))).toBe('1,5 Mo/s'))
})

describe('formatRelative court', () => {
  const now = new Date('2026-10-19T12:00:00Z')

  it.each([
    ['2026-10-19T11:15:00Z', 'il y a 45 min'],
    ['2026-10-18T16:00:00Z', 'il y a 20 h'],
    ['2026-10-12T12:00:00Z', 'il y a 7 j'],
  ])('%s → %s', (iso, expected) => {
    expect(clean(formatRelative(iso, now, 'short'))).toBe(expected)
  })
})

describe('formatRelative', () => {
  const now = new Date('2026-10-19T12:00:00Z')

  it.each([
    ['2026-10-19T11:59:30Z', 'à l’instant'],
    ['2026-10-19T11:15:00Z', 'il y a 45 minutes'],
    ['2026-10-19T09:00:00Z', 'il y a 3 heures'],
    ['2026-10-18T10:00:00Z', 'hier'],
    ['2026-10-12T12:00:00Z', 'il y a 7 jours'],
    ['2026-07-19T12:00:00Z', 'il y a 3 mois'],
  ])('%s → %s', (iso, expected) => {
    expect(clean(formatRelative(iso, now))).toBe(expected)
  })
})

describe('formatDuration', () => {
  it.each([
    [45, '45 s'],
    [720, '12 min'],
    [3 * 3600 + 600, '3 h'],
    [3826476, '44 j'],
  ])('%d s → %s', (seconds, expected) => {
    expect(clean(formatDuration(seconds))).toBe(expected)
  })
})
