import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { formatAge, formatFullIn, formatRate, shortModel, smartTone, StorageCard, type StorageData, type StorageDisk, type StorageProps } from './StorageCard'

const card: CardView<StorageProps> = { id: 's', type: 'storage', title: null, span: 2, rows: 1, refresh: 300, props: { title: 'NAS', system: 'Beta' } }

const data: StorageData = {
  percent: 57.57,
  used: 1055.15,
  total: 1832.68,
  free: 777.53,
  health: null,
  growth: { perDay: 5, fullInDays: 155.5, days: 10 },
  disks: [],
  scrub: null,
}

const disk = (d: Partial<StorageDisk> = {}): StorageDisk => ({
  serial: 'SN-A',
  model: 'ST4000VN006-3CW104',
  capacity: 4000787030016,
  state: 'PASSED',
  temperature: 21,
  hours: 192,
  counters: { reallocated: 0, pending: 0, uncorrectable: 0, crc: 0 },
  ...d,
})
const result = (d: StorageData): CardResult<StorageData> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-10-19T08:01:00+00:00' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('formatRate', () => {
  it.each([
    [5, '+150 Go/mois'],
    [40, '+1,2 To/mois'],
    [-2, '−60 Go/mois'],
    [0.01, 'stable'],
  ])('%d Go/j → %s', (perDay, expected) => expect(formatRate(perDay).replace(/[  ]/g, ' ')).toBe(expected))
})

describe('formatFullIn', () => {
  it.each([
    [12, 'dans ~12 jours'],
    [155.5, 'dans ~5 mois'],
    [900, 'dans ~2 ans'],
    [5000, 'dans plus de 10 ans'],
  ])('%d j → %s', (days, expected) => expect(formatFullIn(days)).toBe(expected))
})

describe('formatAge', () => {
  it.each([
    [5, '5 h'],
    [192, '8 j'],
    [2000, '2 mois'],
    [40215, '4,6 ans'],
  ])('%d h → %s', (hours, expected) => expect(formatAge(hours)).toBe(expected))
})

describe('shortModel', () => {
  it.each([
    ['ST4000VN006-3CW104', 'ST4000VN006'],
    ['WDC WD20SPZX-08UA7', 'WD20SPZX'],
    ['Samsung SSD 990 PRO 2TB', 'Samsung SSD 990 PRO 2TB'],
  ])('%s → %s', (model, expected) => expect(shortModel(model)).toBe(expected))
})

describe('smartTone', () => {
  it.each([
    ['sain', disk(), 'ok'],
    ['secteurs réalloués', disk({ counters: { reallocated: 8, crc: 0 } }), 'warn'],
    ['erreurs CRC (câble)', disk({ counters: { crc: 3 } }), 'warn'],
    ['chaud', disk({ temperature: 52 }), 'warn'],
    ['SMART en échec', disk({ state: 'FAILED' }), 'down'],
    ['absent de Beszel', disk({ state: 'MISSING', model: null, capacity: null, temperature: null, hours: null, counters: {} }), 'down'],
  ] as const)('%s → %s', (_, d, tone) => expect(smartTone(d)).toBe(tone))
})

describe('StorageCard', () => {
  it('jauge de remplissage, utilisé, libre et total', () => {
    const { container } = render(<StorageCard card={card} result={result(data)} />)

    screen.getByRole('heading', { name: 'NAS' })
    expect(screen.getByRole('progressbar', { name: /NAS/ }).getAttribute('aria-valuenow')).toBe('58')
    expect(text(container)).toContain('1 / 1,8 To')
    expect(text(container)).toContain('777,5 Go libres')
  })

  it('rythme de croissance et date où le volume sera plein', () => {
    const { container } = render(<StorageCard card={card} result={result(data)} />)

    expect(text(container)).toContain('+150 Go/mois')
    expect(text(container)).toContain('plein dans ~5 mois')
  })

  it('une ligne par disque : capacité, modèle, température, âge', () => {
    render(<StorageCard card={card} result={result({ ...data, disks: [disk(), disk({ serial: 'SN-B', model: 'WDC WD20SPZX-08UA7', capacity: 2000398934016, hours: 40215, temperature: 19 })] })} />)

    const rows = screen.getAllByRole('listitem').map(text)
    expect(rows).toHaveLength(2)
    expect(rows[0]).toContain('4 To')
    expect(rows[0]).toContain('ST4000VN006')
    expect(rows[0]).toContain('21 °C')
    expect(rows[0]).toContain('8 j')
    expect(rows[1]).toContain('WD20SPZX')
    expect(rows[1]).toContain('4,6 ans')
  })

  it('détail SMART au survol, compteurs non nuls en clair', () => {
    render(<StorageCard card={card} result={result({ ...data, disks: [disk({ counters: { reallocated: 8, crc: 0 } })] })} />)

    const row = screen.getByRole('listitem')
    expect(row.getAttribute('title')).toContain('SN-A')
    expect(row.getAttribute('title')).toContain('SMART PASSED')
    expect(text(row)).toContain('8 réalloués')
  })

  it('disque absent de Beszel', () => {
    render(<StorageCard card={card} result={result({ ...data, disks: [disk({ serial: 'SN-X', state: 'MISSING', model: null, capacity: null, temperature: null, hours: null, counters: {} })] })} />)

    expect(text(screen.getByRole('listitem'))).toContain('SN-X absent')
  })

  it('SMART en échec', () => {
    render(<StorageCard card={card} result={result({ ...data, disks: [disk({ state: 'FAILED' })] })} />)

    expect(text(screen.getByRole('listitem'))).toContain('SMART en échec')
  })

  it.each([
    [{ status: 'up', at: '2026-11-01T17:01:08+01:00', running: false }, 'Scrub 1 nov. · OK', 'Dernier scrub : 1 nov. · sans erreur'],
    [{ status: 'down', at: '2026-11-01T17:01:08+01:00', running: false }, 'Scrub 1 nov. · échec', 'Dernier scrub : 1 nov. · en échec'],
    [{ status: 'grace', at: '2026-10-01T17:01:08+01:00', running: false }, 'Scrub 1 oct. · en retard', 'Dernier scrub : 1 oct. · en retard'],
    [{ status: 'new', at: null, running: false }, 'Scrub : jamais', 'Scrub jamais lancé'],
    [{ status: 'up', at: '2026-12-01T10:00:02+01:00', running: true }, 'Scrub en cours', 'Scrub en cours depuis le 1 déc.'],
    [{ status: 'unavailable', at: null, running: false }, 'Scrub : ?', 'Scrub : état indisponible'],
  ] as const)('scrub %j', (scrub, label, long) => {
    render(<StorageCard card={card} result={result({ ...data, scrub })} />)

    const line = screen.getByTitle(new RegExp(long.replace(/[.?]/g, '\\$&')))
    expect(text(line)).toBe(label)
  })

  it('sans disques ni scrub configurés, rien de plus', () => {
    const { container } = render(<StorageCard card={card} result={result(data)} />)

    expect(screen.queryAllByRole('listitem')).toHaveLength(0)
    expect(text(container)).not.toContain('Scrub')
  })

  it('pas de projection quand le volume ne grossit pas', () => {
    const { container } = render(<StorageCard card={card} result={result({ ...data, growth: { perDay: -1, fullInDays: null, days: 10 } })} />)

    expect(text(container)).not.toContain('plein')
  })

  it('prévient quand le recul manque pour une tendance', () => {
    const { container } = render(<StorageCard card={card} result={result({ ...data, growth: null })} />)

    expect(text(container)).toContain('tendance après 2 jours de mesures')
  })

  it.each([
    ['ONLINE', 'Sain'],
    ['DEGRADED', 'Dégradé'],
    ['UNKNOWN', 'État inconnu'],
  ] as const)('état du pool %s → « %s »', (health, label) => {
    render(<StorageCard card={card} result={result({ ...data, health })} />)

    screen.getByText(label)
  })

  it('pas d’état sans pool configuré', () => {
    render(<StorageCard card={card} result={result(data)} />)

    expect(screen.queryByText('Sain')).toBeNull()
  })

  it('annonce le chargement', () => {
    const { container } = render(<StorageCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
