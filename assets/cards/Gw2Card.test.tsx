import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { coins, Gw2Card, type Gw2Data } from './Gw2Card'

const card: CardView<{ title: string }> = { id: 'g', type: 'gw2', title: null, span: 2, rows: 2, refresh: 300, props: { title: 'Guild Wars 2' } }
const inHours = (h: number) => new Date(Date.now() + h * 3600_000).toISOString()

const data: Gw2Data = {
  account: { name: 'Joueur.1234', fractalLevel: 42, wvwRank: 321 },
  icon: 'https://render.example/waypoint.png',
  coinIcons: { gold: 'https://render.example/gold.png', silver: 'https://render.example/silver.png', copper: 'https://render.example/copper.png' },
  wallet: [
    { id: 1, name: 'Pièce', icon: 'https://render.example/coin.png', value: 1234567 },
    { id: 63, name: 'Acclamations astrales', icon: 'https://render.example/astral.png', value: 1500 },
  ],
  delivery: { coins: 514400, items: 1 },
  vault: {
    daily: { done: 2, total: 4, claimed: false, acclaim: 20, resetAt: inHours(14.2) },
    weekly: { done: 1, total: 6, claimed: false, acclaim: 450, resetAt: inHours(24 * 5 + 2) },
    objectives: [
      { period: 'daily', title: 'Terminer une fractale', current: 0, complete: 1, acclaim: 10 },
      { period: 'weekly', title: 'Vaincre un boss du monde', current: 4, complete: 10, acclaim: 50 },
    ],
  },
  crafting: { done: 1, total: 5 },
}
const result = (d: Gw2Data): CardResult<Gw2Data> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('Gw2Card', () => {
  it('pièces : or, argent, cuivre', () => {
    expect(coins(1234567)).toEqual({ gold: 123, silver: 45, copper: 67 })
    expect(coins(99)).toEqual({ gold: 0, silver: 0, copper: 99 })
  })

  it('compte et or à récupérer au comptoir', () => {
    render(<GwCard />)

    const head = screen.getByRole('heading').parentElement!
    expect(text(head)).toContain('Joueur.1234')
    expect(text(head)).toContain('Fractales 42 · McM 321')
    expect(text(screen.getByTitle(/au comptoir/))).toBe('51 or à récupérer')
  })

  it('portefeuille : or en pièces, autres monnaies avec icône', () => {
    render(<GwCard />)

    const wallet = screen.getByRole('list', { name: 'Portefeuille' }).querySelectorAll('li')
    expect(text(wallet[0]!)).toBe('123 45 67')
    expect(wallet[0]!.getAttribute('title')).toBe('Pièce : 123 or 45 argent 67 cuivre')
    expect(text(wallet[1]!)).toBe('1 500')
    expect(wallet[1]!.querySelector('img')?.getAttribute('src')).toBe('https://render.example/astral.png')
  })

  it('Chambre forte : progression et réinitialisation', () => {
    render(<GwCard />)

    expect(text(screen.getByRole('group', { name: 'Quotidien' }))).toContain('2/4')
    expect(text(screen.getByRole('group', { name: 'Quotidien' }))).toContain('dans 14 h')
    expect(text(screen.getByRole('group', { name: 'Hebdomadaire' }))).toContain('1/6')
    expect(text(screen.getByRole('group', { name: 'Hebdomadaire' }))).toContain('dans 5 j')
  })

  it('objectifs restants, avec leur période et leur récompense', () => {
    render(<GwCard />)

    const items = screen.getByRole('list', { name: 'Objectifs restants' }).querySelectorAll('li')
    expect(text(items[0]!)).toContain('Terminer une fractale')
    expect(text(items[0]!)).toContain('0/1')
    expect(text(items[1]!)).toContain('Hebdo')
    expect(text(items[1]!)).toContain('+50')
  })

  it('trop d’objectifs pour la hauteur : la liste défile au lieu de les couper', () => {
    render(<GwCard />)

    const list = screen.getByRole('list', { name: 'Objectifs restants' })
    expect(list.className).toContain('overflow-y-auto')
    expect(list.className).not.toContain('overflow-hidden')
    expect(list.querySelector('li')?.className).toContain('shrink-0')
  })

  it('tout est fait : le dit', () => {
    render(<Gw2Card card={card} result={result({ ...data, vault: { ...data.vault, objectives: [] } })} />)

    screen.getByText('Tous les objectifs sont faits.')
  })

  it('artisanat quotidien', () => {
    render(<GwCard />)

    screen.getByText('Artisanat 1/5')
  })

  it('annonce le chargement', () => {
    const { container } = render(<Gw2Card card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})

function GwCard() {
  return <Gw2Card card={card} result={result(data)} />
}
