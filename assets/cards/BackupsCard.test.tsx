import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { BackupsCard, type BackupsData, type ResticRun } from './BackupsCard'

const card: CardView<{ title: string }> = { id: 'b', type: 'backups', title: null, span: 2, rows: 1, refresh: 600, props: { title: 'Sauvegardes' } }

const entry = (name: string, state: string, ageHours: number | null, reason: string | null = null) => ({ name, label: name[0]!.toUpperCase() + name.slice(1), state, ageHours, maxHours: 30, latest: `${name}.tar.gz`, reason })
const restic: ResticRun = { finishedAt: '2026-10-20T03:51:41+02:00', durationSeconds: 2200, rc: 0, backupSeconds: 2139, dataAdded: 110_080_000, filesNew: 493, filesChanged: 6101, filesProcessed: 66834, bytesProcessed: 471_063_000_000, copyRc: 0, copySeconds: 28 }
const allOk: BackupsData = {
  checkedAt: new Date(Date.now() - 4 * 3600_000).toISOString(),
  stale: false,
  ok: 2,
  problems: 0,
  total: 2,
  entries: [entry('cuisine', 'ok', 9), entry('restic', 'ok', 8.5)],
  nights: [
    { date: '2026-10-17', problems: ['Galerie'] },
    { date: '2026-10-18', problems: [] },
    { date: '2026-10-19', problems: [] },
    { date: '2026-10-20', problems: [] },
  ],
  restic,
}
const withProblems: BackupsData = {
  ...allOk,
  ok: 2,
  problems: 2,
  total: 4,
  entries: [entry('notes', 'unreachable', null, 'dossier inaccessible'), entry('galerie', 'late', 56, 'dernier backup il y a 52.0 h (seuil 30 h)'), ...allOk.entries],
}
const result = (d: BackupsData): CardResult<BackupsData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')
const tile = (name: string) => screen.getByRole('group', { name })

describe('BackupsCard', () => {
  it('tout est à jour : bilan et nuits sans incident', () => {
    render(<BackupsCard card={card} result={result(allOk)} />)

    expect(text(tile('Sauvegardes'))).toContain('2/2 à jour')
    expect(text(tile('Sauvegardes'))).toContain('3 nuits sans incident')
  })

  it('problèmes : leur nombre et leurs noms', () => {
    render(<BackupsCard card={card} result={result(withProblems)} />)

    expect(text(tile('Sauvegardes'))).toContain('2 à surveiller')
    expect(text(tile('Sauvegardes'))).toContain('Notes, Galerie')
  })

  it('liste complète des sauvegardes au survol', () => {
    render(<BackupsCard card={card} result={result(withProblems)} />)

    expect(tile('Sauvegardes').getAttribute('title')).toBe(['✗ Notes : inaccessible — dossier inaccessible', '✗ Galerie : en retard, 2 j — dernier backup il y a 52.0 h (seuil 30 h)', '✓ Cuisine : 9 h', '✓ Restic : 8 h'].join('\n'))
  })

  it('dernière sauvegarde : durée totale, volume ajouté, copie B2', () => {
    render(<BackupsCard card={card} result={result(allOk)} />)

    expect(text(tile('Dernière sauvegarde'))).toContain('36 min')
    expect(text(tile('Dernière sauvegarde'))).toContain('+110,1 Mo · B2 en 28 s')
    expect(tile('Dernière sauvegarde').getAttribute('title')?.replace(/[\u00a0\u202f]/g, ' ')).toContain('493 nouveaux, 6 101 modifiés')
  })

  it('restic en échec : l’étape en cause', () => {
    render(<BackupsCard card={card} result={result({ ...allOk, restic: { ...restic, rc: 1, copyRc: 1 } })} />)
    expect(text(tile('Dernière sauvegarde'))).toContain('Échec')
    expect(text(tile('Dernière sauvegarde'))).toContain('copie vers B2 (code 1)')
  })

  it('restic en échec avant la copie : la sauvegarde locale', () => {
    render(<BackupsCard card={card} result={result({ ...allOk, restic: { ...restic, rc: 3, dataAdded: null, copyRc: null, copySeconds: null } })} />)

    expect(text(tile('Dernière sauvegarde'))).toContain('sauvegarde locale (code 3)')
  })

  it('sans statut restic : le dit', () => {
    render(<BackupsCard card={card} result={result({ ...allOk, restic: null })} />)

    expect(text(tile('Dernière sauvegarde'))).toContain('—')
  })

  it('sentinelle en retard : signalée dans l’en-tête', () => {
    render(<BackupsCard card={card} result={result({ ...allOk, stale: true, checkedAt: new Date(Date.now() - 50 * 3600_000).toISOString() })} />)

    expect(text(screen.getByRole('alert'))).toContain('sentinelle en retard')
  })

  it('annonce le chargement', () => {
    const { container } = render(<BackupsCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
