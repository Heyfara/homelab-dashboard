import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { C411Card, classLabel, type C411Data, type C411Props } from './C411Card'

const card: CardView<C411Props> = {
  id: 'tom-0-0',
  type: 'c411',
  title: null,
  span: 2,
  rows: 2,
  refresh: 60,
  props: { title: 'C411', service: 'C411', url: 'https://c411.example', icon: 'https://c411.example/apple-touch-icon.png' },
}

const data: C411Data = {
  user: { name: 'membre', avatar: null, memberSince: '2025-03-14T09:12:44+00:00' },
  level: {
    level: 25,
    xp: 5738,
    xpIntoLevel: 350,
    xpToNextLevel: 37,
    progress: 0.9044,
    badge: { emblem: 'https://c411.example/images/ic-grade/embleme-blue.webp', dial: 'https://c411.example/images/ic-grade/cadran-silver.webp' },
  },
  class: {
    slug: 'team_resident_mainstream',
    grade: { tier: 'argent', level: 2 },
    stars: 2,
    questsCompleted: 23,
    questsTotal: 40,
    bonusPercent: 2.5,
    voies: [
      { key: 'seed', star: true, completed: 8, total: 8 },
      { key: 'upload', star: false, completed: 5, total: 11 },
      { key: 'specialization', star: true, completed: 6, total: 6 },
      { key: 'competitivity', star: false, completed: 0, total: 0 },
    ],
  },
  team: { name: 'Example Team', tag: 'EXT' },
  zen: { balance: 1840, earned: 12650, spent: 10810 },
  transfer: { uploaded: 1_317_752_497_355, downloaded: 100_430_774_996, ratio: 13.12 },
  download: { allowed: true, blockedReason: null },
  freeleech: { active: false, sources: [] },
}

const result = (d: C411Data): CardResult<C411Data> => ({ status: 'ok', data: d, error: null, fetchedAt: '2026-10-19T12:00:00+00:00' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('classLabel', () => {
  it('met le slug en forme', () => {
    expect(classLabel('tanker')).toBe('Tanker')
    expect(classLabel('team_resident_mainstream')).toBe('Team resident mainstream')
  })
})

describe('C411Card', () => {
  it('titre = logo du site ; le pseudo et l’équipe sont dans la carte', () => {
    render(<C411Card card={card} result={result(data)} />)

    const heading = screen.getByRole('heading', { name: 'C411' })
    expect(heading.querySelector('img')?.getAttribute('src')).toBe('https://c411.example/apple-touch-icon.png')
    expect(screen.getByText('membre').closest('header')).toBeNull()
    expect(screen.getByText('EXT').closest('header')).toBeNull()
    expect(screen.getByRole('link', { name: /Ouvrir C411/ }).getAttribute('href')).toBe('https://c411.example')
  })

  it('montre le niveau sur le badge C411 : emblème derrière, cadran devant', () => {
    render(<C411Card card={card} result={result(data)} />)

    const badge = screen.getByRole('img', { name: 'Niveau 25' })
    const [emblem, dial] = Array.from(badge.querySelectorAll('img'))
    expect(emblem!.getAttribute('src')).toBe(data.level.badge.emblem)
    expect(dial!.getAttribute('src')).toBe(data.level.badge.dial)
    expect(text(badge)).toBe('25')
    expect(screen.queryByRole('progressbar', { name: /Progression vers/ })).toBeNull()
  })

  it('montre l’expérience dans une barre verte, avec le total', () => {
    render(<C411Card card={card} result={result(data)} />)

    const bar = screen.getByRole('progressbar', { name: 'Expérience du niveau 25' })
    expect(bar.getAttribute('aria-valuenow')).toBe('90')
    const block = screen.getByRole('group', { name: 'Expérience' })
    expect(text(block)).toContain('350 / 387 XP')
    expect(text(block)).toContain('encore 37')
    expect(text(block)).toContain('5 738 XP au total')
    expect(bar.firstElementChild?.className).toContain('from-emerald-500')
  })

  it('montre la classe, son emblème et ses quêtes', () => {
    const { container } = render(<C411Card card={card} result={result(data)} />)

    expect(text(container)).toContain('Team resident mainstream')
    expect(text(container)).toContain('Argent II')
    expect(text(container)).toContain('23 / 40 quêtes')
  })

  it('une barre par voie, dans l’ordre, étoile obtenue signalée', () => {
    render(<C411Card card={card} result={result(data)} />)

    const voies = screen.getAllByRole('listitem')
    expect(voies.map((v) => text(v))).toEqual([
      expect.stringContaining('Seed8 / 8'),
      expect.stringContaining('Upload5 / 11'),
      expect.stringContaining('Spécialisation6 / 6'),
      expect.stringContaining('Compétitivité0 / 0'),
    ])
    expect(screen.getAllByLabelText('étoile obtenue')).toHaveLength(2)
  })

  it('chaque voie a sa couleur', () => {
    const { container } = render(<C411Card card={card} result={result(data)} />)

    const fills = Array.from(container.querySelectorAll('[data-voie-fill]')).map((el) => el.className.match(/bg-voie-\w+/)?.[0])
    expect(fills).toEqual(['bg-voie-seed', 'bg-voie-upload', 'bg-voie-specialization', 'bg-voie-competitivity'])
  })

  it('affiche Zen, volumes et ratio', () => {
    const { container } = render(<C411Card card={card} result={result(data)} />)

    const content = text(container)
    expect(content).toContain('1 840')
    expect(content).toContain('1,3 To')
    expect(content).toContain('100,4 Go')
    expect(content).toContain('×13,1')
  })

  it('signale un téléchargement bloqué', () => {
    render(<C411Card card={card} result={result({ ...data, download: { allowed: false, blockedReason: 'insufficient_balance' } })} />)

    screen.getByText('Téléchargement bloqué : solde insuffisant')
  })

  it('signale le freeleech', () => {
    render(<C411Card card={card} result={result({ ...data, freeleech: { active: true, sources: ['campaign'] } })} />)

    screen.getByText('Freeleech')
  })

  it('sans logo, le titre est écrit', () => {
    render(<C411Card card={{ ...card, props: { ...card.props, icon: null } }} result={result(data)} />)

    expect(screen.getByRole('heading', { name: 'C411' }).querySelector('img')).toBeNull()
  })

  it('sans classe', () => {
    render(<C411Card card={card} result={result({ ...data, class: null })} />)

    screen.getByText('Aucune classe choisie')
  })

  it('annonce le chargement', () => {
    const { container } = render(<C411Card card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
