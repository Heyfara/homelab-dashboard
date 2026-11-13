import { act, createEvent, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { snapshots } from '../http/snapshots'
import { DashboardPage } from './DashboardPage'

// Type sans composant React : la carte s'affiche (cadre d'erreur) sans rien charger.
const card = (id: string, title: string) => ({ id, type: 'factice', title, span: 1, rows: 1, refresh: 60, props: {} })

const dashboard = (order: string[], customized = false) => ({
  profile: { slug: 'alice', name: 'Alice', color: 'indigo' },
  customized,
  settings: { columns: 3, refresh: 60 },
  sections: [
    { title: 'Services', columns: 3, cards: order.map((id) => card(id, id.toUpperCase())) },
    { title: 'Jeux', columns: 3, cards: [card('z', 'Z')] },
  ],
})

type Call = { url: string; init?: RequestInit }

function serve(initial = dashboard(['a', 'b', 'c'])) {
  const calls: Call[] = []
  let current = initial
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      calls.push({ url, init })
      if (url === '/api/profiles/alice/layout') {
        if (init?.method === 'DELETE') current = dashboard(['a', 'b', 'c'])
        return new Response(null, { status: 204 })
      }
      return new Response(JSON.stringify(current), { status: 200, headers: { 'Content-Type': 'application/json' } })
    }),
  )
  return calls
}

const titles = (section = 'Services') =>
  within(screen.getByRole('region', { name: section }))
    .getAllByTestId(/^card-/)
    .map((el) => el.dataset.testid!.slice(5).toUpperCase())

// jsdom n'a pas de DragEvent : la position du pointeur est posée à la main.
function dragOver(el: HTMLElement, clientX: number) {
  const event = createEvent.dragOver(el)
  Object.defineProperty(event, 'clientX', { value: clientX })
  fireEvent(el, event)
}

afterEach(() => vi.unstubAllGlobals())

describe('DashboardPage : réorganiser les cartes', () => {
  it('déplace avec les flèches puis enregistre l’ordre de toutes les cartes', async () => {
    const calls = serve()
    render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)
    await screen.findByRole('button', { name: 'Réorganiser' })
    fireEvent.click(screen.getByRole('button', { name: 'Réorganiser' }))

    fireEvent.click(screen.getByRole('button', { name: 'Déplacer « A » vers la droite' }))
    expect(titles()).toEqual(['B', 'A', 'C'])
    expect(screen.getByRole('button', { name: 'Déplacer « B » vers la gauche' })).toHaveProperty('disabled', true)

    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))
    await waitFor(() => expect(screen.queryByRole('button', { name: /Déplacer/ })).toBeNull())

    const put = calls.find((c) => c.init?.method === 'PUT')
    expect(put?.url).toBe('/api/profiles/alice/layout')
    expect(JSON.parse(String(put?.init?.body))).toEqual({ order: ['b', 'a', 'c', 'z'] })
    expect(screen.queryByRole('button', { name: /Déplacer/ })).toBeNull()
    expect(titles()).toEqual(['B', 'A', 'C'])
  })

  it('annuler remet l’ordre d’avant', async () => {
    const calls = serve()
    render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Réorganiser' }))
    fireEvent.click(screen.getByRole('button', { name: 'Déplacer « C » vers la gauche' }))

    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }))

    expect(titles()).toEqual(['A', 'B', 'C'])
    expect(calls.some((c) => c.init?.method === 'PUT')).toBe(false)
  })

  it('« Ordre par défaut » n’apparaît que si l’ordre a été changé, et l’oublie', async () => {
    const calls = serve(dashboard(['c', 'a', 'b'], true))
    render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Réorganiser' }))

    fireEvent.click(screen.getByRole('button', { name: 'Ordre par défaut' }))

    await waitFor(() => expect(calls.some((c) => c.url === '/api/profiles/alice/layout' && c.init?.method === 'DELETE')).toBe(true))
    await waitFor(() => expect(titles()).toEqual(['A', 'B', 'C']))
    expect(screen.queryByRole('button', { name: 'Ordre par défaut' })).toBeNull()
  })

  it('glisser : les autres cartes se poussent pendant le survol, Échap annule', async () => {
    serve()
    render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Réorganiser' }))
    const slot = (id: string) => screen.getByTestId(`card-${id}`)
    // jsdom ne calcule pas de mise en page : chaque carte fait 100 px de large, côte à côte.
    for (const [i, id] of ['a', 'b', 'c', 'z'].entries()) {
      vi.spyOn(slot(id), 'getBoundingClientRect').mockReturnValue({ left: i * 100, width: 100, top: 0, height: 100 } as DOMRect)
    }

    fireEvent.dragStart(slot('a'))
    dragOver(slot('c'), 280)
    expect(titles()).toEqual(['B', 'C', 'A'])

    // Survol d'une autre section : rien ne bouge (une carte reste dans sa section).
    dragOver(slot('z'), 350)
    expect(titles()).toEqual(['B', 'C', 'A'])
    expect(titles('Jeux')).toEqual(['Z'])

    act(() => {
      fireEvent.dragEnd(slot('a'), { dataTransfer: { dropEffect: 'none' } })
    })
    expect(titles()).toEqual(['A', 'B', 'C'])
  })

  it('glisser puis déposer garde le nouvel ordre, à enregistrer', async () => {
    const calls = serve()
    render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Réorganiser' }))
    const slot = (id: string) => screen.getByTestId(`card-${id}`)
    for (const [i, id] of ['a', 'b', 'c'].entries()) {
      vi.spyOn(slot(id), 'getBoundingClientRect').mockReturnValue({ left: i * 100, width: 100, top: 0, height: 100 } as DOMRect)
    }

    fireEvent.dragStart(slot('c'))
    dragOver(slot('a'), 10)
    fireEvent.drop(slot('a'), { clientX: 10 })
    fireEvent.dragEnd(slot('c'), { dataTransfer: { dropEffect: 'move' } })

    expect(titles()).toEqual(['C', 'A', 'B'])
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))
    await waitFor(() => expect(calls.some((c) => c.init?.method === 'PUT')).toBe(true))
    expect(JSON.parse(String(calls.find((c) => c.init?.method === 'PUT')?.init?.body))).toEqual({ order: ['c', 'a', 'b', 'z'] })
  })
})

describe('DashboardPage : connexion mauvaise ou coupée', () => {
  it('garde la mise en page reçue', async () => {
    serve()
    render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)
    await screen.findByRole('button', { name: 'Réorganiser' })

    expect(snapshots.dashboard('alice')?.sections[0]?.cards.map((c) => c.id)).toEqual(['a', 'b', 'c'])
  })

  it('affiche la dernière mise en page gardée quand le serveur ne répond pas', async () => {
    snapshots.saveDashboard('alice', dashboard(['c', 'b', 'a']) as never)
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('Failed to fetch'))))
    render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)

    expect(titles()).toEqual(['C', 'B', 'A'])
    await screen.findByText(/dernières données gardées/)
  })

  it('oublie les données des cartes retirées du profil', async () => {
    snapshots.saveCard('alice-ancienne', { status: 'ok', data: {}, error: null, fetchedAt: '2026-10-18T10:00:00+00:00' })
    serve()
    render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)
    await screen.findByRole('button', { name: 'Réorganiser' })

    expect(snapshots.card('alice-ancienne')).toBeNull()
  })

  it('réessaie de charger la page après un échec', async () => {
    vi.useFakeTimers()
    try {
      let attempts = 0
      vi.stubGlobal(
        'fetch',
        vi.fn(async () => {
          if (++attempts === 1) throw new TypeError('Failed to fetch')
          return new Response(JSON.stringify(dashboard(['a'])), { status: 200 })
        }),
      )
      render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)
      await act(async () => {})
      screen.getByText(/Failed to fetch/)

      await act(() => vi.advanceTimersByTimeAsync(5_000))
      expect(titles()).toEqual(['A'])
    } finally {
      vi.useRealTimers()
    }
  })
})

describe('DashboardPage : onglets', () => {
  const tabbed = () => ({
    ...dashboard(['a', 'b']),
    tabs: [
      { slug: 'accueil', title: 'Accueil' },
      { slug: 'admin', title: 'Admin' },
    ],
    sections: [
      { title: 'Services', columns: 3, tab: 'accueil', cards: [card('a', 'A'), card('b', 'B')] },
      { title: 'Machines', columns: 3, tab: 'admin', cards: [card('m', 'M')] },
    ],
  })

  it('n’affiche que les sections de l’onglet ouvert, le premier par défaut', async () => {
    serve(tabbed() as never)
    const { rerender } = render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)

    await screen.findByRole('region', { name: 'Services' })
    expect(screen.queryByRole('region', { name: 'Machines' })).toBeNull()
    expect(screen.getByRole('link', { name: 'Accueil' }).getAttribute('aria-current')).toBe('page')
    expect(screen.getByRole('main').contains(screen.getByRole('navigation', { name: 'Onglets' }))).toBe(false) // barre latérale, à côté du contenu

    rerender(<DashboardPage slug="alice" tab="admin" onChangeProfile={() => {}} />)
    screen.getByRole('region', { name: 'Machines' })
    expect(screen.queryByRole('region', { name: 'Services' })).toBeNull()
  })

  it('un onglet inconnu ouvre le premier', async () => {
    serve(tabbed() as never)
    render(<DashboardPage slug="alice" tab="disparu" onChangeProfile={() => {}} />)

    await screen.findByRole('region', { name: 'Services' })
  })

  it('pas de barre d’onglets pour un profil à un seul onglet', async () => {
    serve({ ...dashboard(['a']), tabs: [{ slug: 'accueil', title: 'Accueil' }] } as never)
    render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)

    await screen.findByRole('button', { name: 'Réorganiser' })
    expect(screen.queryByRole('navigation', { name: 'Onglets' })).toBeNull()
  })

  it('réorganiser dans un onglet enregistre l’ordre de toutes les cartes', async () => {
    const calls = serve(tabbed() as never)
    render(<DashboardPage slug="alice" tab="admin" onChangeProfile={() => {}} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Réorganiser' }))
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => expect(calls.some((c) => c.init?.method === 'PUT')).toBe(true))
    const put = calls.find((c) => c.init?.method === 'PUT')!
    expect(JSON.parse(String(put.init!.body))).toEqual({ order: ['a', 'b', 'm'] })
  })
})

describe('DashboardPage : cloche', () => {
  it('n’apparaît que si le profil suit des sujets ntfy', async () => {
    serve()
    const { unmount } = render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)
    await screen.findByRole('button', { name: 'Réorganiser' })
    expect(screen.queryByRole('button', { name: /^Notifications/ })).toBeNull()
    unmount()

    const calls: string[] = []
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string) => {
        calls.push(url)
        const body =
          url === '/api/profiles/alice/notifications'
            ? { topics: [], link: null, unread: 1, messages: [{ id: 'm1', time: '2026-11-13T17:50:00+01:00', topic: 'maison', title: 'Pile faible', message: 'Salon', priority: 3, tags: [], click: null, read: false }] }
            : { ...dashboard(['a']), profile: { slug: 'alice', name: 'Alice', color: 'indigo', notifications: true } }
        return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
      }),
    )
    render(<DashboardPage slug="alice" onChangeProfile={() => {}} />)

    expect(await screen.findByRole('button', { name: 'Notifications : 1 non lue' })).toBeTruthy()
    expect(calls).toContain('/api/profiles/alice/notifications')
  })
})
