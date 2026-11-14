import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { NotificationsView } from '../http/types'
import { NotificationBell, UrgentBanner, useNotifications } from './Notifications'

const NOW = new Date('2026-11-13T18:00:00+01:00')

const view = (read: string[] = []): NotificationsView => {
  const messages = [
    { id: 'm3', time: '2026-11-13T17:58:00+01:00', topic: 'maison', title: null, message: 'Porte du garage ouverte', priority: 3, tags: [], click: null },
    { id: 'm2', time: '2026-11-13T17:50:00+01:00', topic: 'urgent', title: 'Fumée détectée', message: 'Salon', priority: 5, tags: ['rotating_light', 'fumee'], click: null },
    { id: 'm1', time: '2026-11-12T09:00:00+01:00', topic: 'homelab', title: 'Réparation à faire', message: 'netatmo', priority: 3, tags: ['wrench'], click: 'https://ha.test/config/repairs' },
  ].map((m) => ({ ...m, read: read.includes(m.id) }))
  return {
    topics: [
      { id: 'urgent', name: 'Urgent' },
      { id: 'maison', name: 'Maison' },
      { id: 'homelab', name: 'Homelab' },
    ],
    link: 'https://ntfy.test',
    unread: messages.filter((m) => !m.read).length,
    messages,
  }
}

type Call = { url: string; init?: RequestInit }

function serve(respond: () => Response = () => json(view())) {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      calls.push({ url, init })
      if (url.endsWith('/read')) return new Response(null, { status: 204 })
      return respond()
    }),
  )
  return calls
}

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

function Harness() {
  const notifications = useNotifications('alice')
  return (
    <>
      <UrgentBanner notifications={notifications} />
      <NotificationBell notifications={notifications} now={NOW} />
    </>
  )
}

const posted = (calls: Call[]) => calls.filter((c) => c.init?.method === 'POST').map((c) => JSON.parse(String(c.init?.body)))

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('Notifications', () => {
  it('compte les messages non lus sur la cloche', async () => {
    serve(() => json(view(['m1'])))
    render(<Harness />)

    const bell = await screen.findByRole('button', { name: 'Notifications : 2 non lues' })
    expect(bell.textContent).toBe('2')
  })

  it('ouvre l’historique, le plus récent d’abord, le sujet à la place d’un titre absent', async () => {
    serve()
    render(<Harness />)
    fireEvent.click(await screen.findByRole('button', { name: /^Notifications/ }))

    const panel = screen.getByRole('dialog', { name: 'Notifications' })
    const items = within(panel).getAllByRole('listitem')
    expect(items.map((li) => within(li).getByRole('heading').textContent)).toEqual(['Maison', '🚨 Fumée détectée', '🔧 Réparation à faire'])
    expect(items[0]!.textContent).toContain('Porte du garage ouverte')
    expect(within(items[2]!).getByRole('link', { name: 'Ouvrir' }).getAttribute('href')).toBe('https://ha.test/config/repairs')
    expect(within(panel).getByText('Hier')).toBeTruthy()
  })

  it('marque un message lu quand on le touche', async () => {
    const calls = serve()
    render(<Harness />)
    fireEvent.click(await screen.findByRole('button', { name: /^Notifications/ }))

    fireEvent.click(screen.getByRole('button', { name: /Porte du garage ouverte/ }))

    expect(posted(calls)).toEqual([{ ids: ['m3'] }])
    expect(calls.at(-1)?.url).toBe('/api/profiles/alice/notifications/read')
    expect(screen.getByRole('button', { name: 'Notifications : 2 non lues' })).toBeTruthy()
  })

  it('marque tout comme lu', async () => {
    const calls = serve()
    render(<Harness />)
    fireEvent.click(await screen.findByRole('button', { name: /^Notifications/ }))

    fireEvent.click(screen.getByRole('button', { name: 'Tout marquer comme lu' }))

    expect(posted(calls)).toEqual([{ ids: ['m3', 'm2', 'm1'] }])
    expect(screen.getByRole('button', { name: 'Notifications : aucune non lue' })).toBeTruthy()
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('affiche un bandeau pour un message urgent non lu, jusqu’à « Vu »', async () => {
    const calls = serve()
    render(<Harness />)

    const banner = await screen.findByRole('alert')
    expect(banner.textContent).toContain('Fumée détectée')
    expect(banner.textContent).toContain('Salon')

    fireEvent.click(within(banner).getByRole('button', { name: 'Vu' }))

    expect(posted(calls)).toEqual([{ ids: ['m2'] }])
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('ferme l’historique avec Échap', async () => {
    serve()
    render(<Harness />)
    fireEvent.click(await screen.findByRole('button', { name: /^Notifications/ }))

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('signale un serveur ntfy injoignable sans cacher la cloche', async () => {
    serve(() => json({ error: 'ntfy injoignable : Connection refused' }, 502))
    render(<Harness />)

    fireEvent.click(await screen.findByRole('button', { name: 'Notifications : indisponibles' }))

    expect(screen.getByRole('dialog').textContent).toContain('ntfy injoignable : Connection refused')
  })

  it('relit les messages régulièrement', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let read = ['m1', 'm2', 'm3']
    const calls = serve(() => json(view(read)))
    render(<Harness />)
    await screen.findByRole('button', { name: 'Notifications : aucune non lue' })

    read = ['m1', 'm2']
    await act(() => vi.advanceTimersByTimeAsync(30_000))

    await waitFor(() => expect(screen.getByRole('button', { name: 'Notifications : 1 non lue' })).toBeTruthy())
    expect(calls.filter((c) => c.url === '/api/profiles/alice/notifications').length).toBe(2)
  })
})
