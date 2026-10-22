import { act, createEvent, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
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
