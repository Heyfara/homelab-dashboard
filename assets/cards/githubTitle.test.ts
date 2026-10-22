import { describe, expect, it } from 'vitest'
import { labelTone, parsePrTitle } from './githubTitle'

describe('parsePrTitle', () => {
  it.each([
    [
      'chore(deps): Update ghcr.io/music-assistant/server Docker tag to v2.10.7',
      { name: 'music-assistant/server', version: 'v2.10.7', icon: 'sh-music-assistant', group: false },
    ],
    [
      'chore(deps): Update ghcr.io/home-assistant/home-assistant Docker tag to v2026.10.2',
      { name: 'home-assistant/home-assistant', version: 'v2026.10.2', icon: 'sh-home-assistant', group: false },
    ],
    [
      'chore(deps): update dependency vite to v8.4.0',
      { name: 'vite', version: 'v8.4.0', icon: 'sh-vite', group: false },
    ],
    [
      'chore(deps): Update docker.io/library/redis Docker tag to v7.4.12',
      { name: 'redis', version: 'v7.4.12', icon: 'sh-redis', group: false },
    ],
    [
      'fix(deps): update module github.com/foo/bar to v1.2.3',
      { name: 'github.com/foo/bar', version: 'v1.2.3', icon: 'sh-bar', group: false },
    ],
    [
      'chore(deps): Update ghcr.io/immich-app/immich-server Docker tag to v2.4.0',
      { name: 'immich-app/immich-server', version: 'v2.4.0', icon: 'sh-immich', group: false },
    ],
    ['chore(deps): Update services applicatifs', { name: 'services applicatifs', version: null, icon: null, group: true }],
    ['chore(deps): Update moteur Docker', { name: 'moteur Docker', version: null, icon: null, group: true }],
    ['Ajoute la carte GitHub', { name: 'Ajoute la carte GitHub', version: null, icon: null, group: false }],
  ])('%s', (title, expected) => {
    expect(parsePrTitle(title)).toEqual(expected)
  })
})

describe('labelTone', () => {
  it.each([
    ['backup-avant', '#ededed', 'danger'],
    ['securite', '#ededed', 'danger'],
    ['breaking-change', '#ededed', 'danger'],
    ['revue-requise', '#ededed', 'warning'],
    ['needs-review', '#ededed', 'warning'],
    ['automerge', '#ededed', 'success'],
  ] as const)('%s → %s', (name, color, tone) => {
    expect(labelTone(name, color)).toEqual({ kind: 'tone', tone })
  })

  it('garde la vraie couleur d’une étiquette colorée sur GitHub', () => {
    expect(labelTone('dependencies', '#0366d6')).toEqual({ kind: 'color', color: '#0366d6' })
  })

  it('une étiquette grise sans sens connu reçoit une couleur stable tirée de son nom', () => {
    const first = labelTone('infra', '#ededed')
    expect(first.kind).toBe('palette')
    expect(labelTone('infra', '#ededed')).toEqual(first)
  })
})
