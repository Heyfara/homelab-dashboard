import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import type { CardComponent } from '../cards/registry'
import { snapshots } from '../http/snapshots'
import { CardSlot } from './CardSlot'

const card: CardView = { id: 'c1', type: 'echo', title: 'Écho', span: 1, rows: 1, refresh: 30, props: { text: 'bonjour' } }
const Echo: CardComponent<{ text: string }, { n: number }> = ({ card, result, feeds }) => (
  <p>
    {card.props.text} {result?.status === 'ok' ? result.data.n : '…'}
    {feeds?.live?.status === 'ok' && ` live ${(feeds.live.data as { n: number }).n}`}
  </p>
)
const registry = { echo: Echo as CardComponent }

const okResult: CardResult = { status: 'ok', data: { n: 7 }, error: null, fetchedAt: '2026-10-18T10:00:00+00:00' }

describe('CardSlot', () => {
  it('rend le composant du type avec ses props et ses données', async () => {
    const fetcher = vi.fn(async () => okResult)
    render(<CardSlot card={card} registry={registry} fetcher={fetcher} />)

    await screen.findByText('bonjour 7')
    expect(fetcher).toHaveBeenCalledWith('c1', expect.any(AbortSignal))
  })

  it('interroge chaque flux à part et le passe au composant', async () => {
    const fetcher = vi.fn(async (_id: string, _signal: AbortSignal, feed?: string): Promise<CardResult> => (feed === 'live' ? { ...okResult, data: { n: 42 } } : okResult))
    render(<CardSlot card={{ ...card, feeds: { live: 5 } }} registry={registry} fetcher={fetcher} />)

    await screen.findByText('bonjour 7 live 42')
    expect(fetcher).toHaveBeenCalledWith('c1', expect.any(AbortSignal), 'live')
  })

  it('signale un type sans composant', () => {
    render(<CardSlot card={{ ...card, type: 'meteo' }} registry={registry} fetcher={vi.fn()} />)

    screen.getByText(/meteo/)
  })

  it('affiche l’erreur renvoyée par le serveur', async () => {
    const fetcher = vi.fn(async (): Promise<CardResult> => ({ status: 'error', data: null, error: 'Beszel injoignable', fetchedAt: '' }))
    render(<CardSlot card={card} registry={registry} fetcher={fetcher} />)

    await screen.findByText('Beszel injoignable')
  })

  it('affiche l’échec réseau', async () => {
    const fetcher = vi.fn(async (): Promise<CardResult> => {
      throw new Error('Failed to fetch')
    })
    render(<CardSlot card={card} registry={registry} fetcher={fetcher} />)

    await screen.findByText(/Failed to fetch/)
  })
  it('affiche tout de suite les dernières données gardées, avec leur âge si elles sont vieilles', async () => {
    const old = new Date(Date.now() - 3 * 3600_000).toISOString()
    snapshots.saveCard('c1', { ...okResult, data: { n: 3 }, fetchedAt: old })
    render(<CardSlot card={card} registry={registry} fetcher={() => new Promise<CardResult>(() => {})} />)

    screen.getByText('bonjour 3')
    screen.getByText(/Données d’il y a 3 heures/)
  })

  it('n’affiche pas l’âge de données récentes', async () => {
    const fetcher = vi.fn(async (): Promise<CardResult> => ({ ...okResult, fetchedAt: new Date().toISOString() }))
    render(<CardSlot card={card} registry={registry} fetcher={fetcher} />)

    await screen.findByText('bonjour 7')
    expect(screen.queryByText(/Données d’il y a/)).toBeNull()
  })

  it('garde les données d’un flux à part de celles de la carte', async () => {
    const fetcher = vi.fn(async (_id: string, _signal: AbortSignal, feed?: string): Promise<CardResult> => (feed === 'live' ? { ...okResult, data: { n: 42 } } : okResult))
    render(<CardSlot card={{ ...card, feeds: { live: 5 } }} registry={registry} fetcher={fetcher} />)

    await screen.findByText('bonjour 7 live 42')
    expect(snapshots.card('c1')?.data).toEqual({ n: 7 })
    expect(snapshots.card('c1?feed=live')?.data).toEqual({ n: 42 })
  })
})
