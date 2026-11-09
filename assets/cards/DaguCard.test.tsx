import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { DaguCard, taskStatus, type DaguData, type DaguTask } from './DaguCard'

const card: CardView<{ title: string; url: string }> = { id: 't', type: 'dagu', title: null, span: 2, rows: 2, refresh: 60, props: { title: 'Tâches', url: 'https://taches.example' } }

const hoursAgo = (h: number) => new Date(Date.now() - h * 3_600_000).toISOString()
const inHours = (h: number) => new Date(Date.now() + h * 3_600_000).toISOString()

const nuit: DaguTask = {
  name: 'nuit',
  title: 'Sauvegardes',
  description: 'Sauvegardes de la nuit',
  schedule: 'chaque jour à 3 h 05',
  nextRun: inHours(17),
  suspended: false,
  lastRun: { state: 'running', startedAt: hoursAgo(5), finishedAt: null, manual: false },
  history: [
    { state: 'failed', startedAt: '2026-11-07T03:05:00+01:00', manual: false },
    { state: 'ok', startedAt: '2026-11-08T03:05:00+01:00', manual: true },
    { state: 'running', startedAt: hoursAgo(5), manual: false },
  ],
  url: 'https://taches.example/dags/nuit',
}
const papiers: DaguTask = {
  ...nuit,
  name: 'papiers',
  title: 'Papiers',
  description: 'Import des documents',
  schedule: 'le lundi à 7 h 30',
  lastRun: { state: 'failed', startedAt: hoursAgo(2), finishedAt: hoursAgo(2), manual: false },
  history: [{ state: 'failed', startedAt: hoursAgo(2), manual: false }],
  url: 'https://taches.example/dags/papiers',
}
const data: DaguData = { tasks: [nuit, papiers] }
const result = (d: DaguData): CardResult<DaguData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[\u00a0\u202f]/g, ' ')

describe('taskStatus', () => {
  it('libellé et couleur de chaque état', () => {
    expect(taskStatus(nuit)).toEqual({ tone: 'unknown', label: 'en cours' })
    expect(taskStatus(papiers)).toEqual({ tone: 'down', label: 'échec' })
    expect(taskStatus({ ...nuit, lastRun: { ...nuit.lastRun!, state: 'ok' } })).toEqual({ tone: 'ok', label: 'réussie' })
    expect(taskStatus({ ...nuit, lastRun: { ...nuit.lastRun!, state: 'partial' } })).toEqual({ tone: 'warn', label: 'en partie' })
    expect(taskStatus({ ...nuit, lastRun: null })).toEqual({ tone: 'unknown', label: 'jamais lancée' })
    expect(taskStatus({ ...nuit, suspended: true })).toEqual({ tone: 'unknown', label: 'suspendue' })
  })
})

describe('DaguCard', () => {
  it('une ligne par tâche, qui ouvre sa page dans Dagu', () => {
    render(<DaguCard card={card} result={result(data)} />)

    const link = screen.getByRole('link', { name: /Sauvegardes/ })
    expect(link.getAttribute('href')).toBe('https://taches.example/dags/nuit')
    expect(text(link)).toContain('en cours')
    expect(text(link)).toContain('depuis 5 h')
    expect(text(link)).toContain('chaque jour à 3 h 05')
    expect(link.getAttribute('title')).toContain('Sauvegardes de la nuit')
  })

  it('tâche terminée : quand, et le prochain passage', () => {
    render(<DaguCard card={card} result={result(data)} />)

    const link = screen.getByRole('link', { name: /Papiers/ })
    expect(text(link)).toContain('il y a 2 h')
    expect(text(link)).toContain('prochain dans 17 h')
  })

  it('historique : une pastille par passage, passages manuels signalés', () => {
    render(<DaguCard card={card} result={result(data)} />)

    const runs = screen.getAllByRole('img', { name: /^Sauvegardes, passage/ })
    expect(runs).toHaveLength(3)
    expect(runs[0]!.getAttribute('aria-label')).toContain('échec')
    expect(runs[1]!.getAttribute('aria-label')).toContain('(à la main)')
  })

  it('lien vers Dagu dans l’en-tête', () => {
    render(<DaguCard card={card} result={result(data)} />)

    expect(screen.getByRole('link', { name: 'Ouvrir Dagu →' }).getAttribute('href')).toBe('https://taches.example')
  })

  it('tâches en échec comptées dans l’en-tête', () => {
    render(<DaguCard card={card} result={result(data)} />)

    expect(text(screen.getByRole('alert'))).toBe('1 en échec')
  })

  it('aucune tâche : le dit', () => {
    render(<DaguCard card={card} result={result({ tasks: [] })} />)

    expect(screen.getByText(/Aucune tâche/)).toBeTruthy()
  })

  it('chargement', () => {
    render(<DaguCard card={card} result={null} />)

    expect(document.querySelector('[aria-busy="true"]')).toBeTruthy()
  })
})
