import { render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { App } from './App'

const profiles = [
  { slug: 'alice', name: 'Alice', color: 'indigo' },
  { slug: 'bob', name: 'Bob', color: 'rose' },
]
const dashboard = (slug: string, name: string) => ({
  profile: { slug, name, color: 'indigo' },
  settings: { columns: 4, refresh: 30 },
  sections: [{ title: 'Au quotidien', columns: 4, cards: [] }],
})

function mockApi() {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string) => {
      const body =
        url === '/api/profiles' ? profiles : url === '/api/dashboards/alice' ? dashboard('alice', 'Alice') : url === '/api/dashboards/bob' ? dashboard('bob', 'Bob') : null
      return new Response(JSON.stringify(body ?? { error: 'Profil inconnu : ' + url }), { status: body ? 200 : 404 })
    }),
  )
}

describe('App', () => {
  beforeEach(() => {
    mockApi()
    localStorage.clear()
    window.history.replaceState(null, '', '/')
  })
  afterEach(() => vi.unstubAllGlobals())

  it('propose les profils à l’accueil', async () => {
    render(<App />)

    await screen.findByRole('button', { name: /Alice/ })
    screen.getByRole('button', { name: /Bob/ })
  })

  it('ouvre le dashboard du profil choisi et s’en souvient', async () => {
    render(<App />)
    ;(await screen.findByRole('button', { name: /Bob/ })).click()

    await screen.findByText('Au quotidien')
    expect(window.location.pathname).toBe('/bob')
    expect(localStorage.getItem('dashboard.profile')).toBe('bob')
  })

  it('renvoie au dernier profil depuis l’accueil', async () => {
    localStorage.setItem('dashboard.profile', 'alice')
    render(<App />)

    await screen.findByText('Au quotidien')
    expect(window.location.pathname).toBe('/alice')
  })

  it('« Changer de profil » revient au choix et oublie le profil', async () => {
    window.history.replaceState(null, '', '/alice')
    render(<App />)
    ;(await screen.findByRole('button', { name: /Changer de profil/ })).click()

    await screen.findByRole('button', { name: /Bob/ })
    expect(window.location.pathname).toBe('/')
    expect(localStorage.getItem('dashboard.profile')).toBeNull()
  })

  it('explique un profil inconnu', async () => {
    window.history.replaceState(null, '', '/carole')
    render(<App />)

    await screen.findByText(/Profil inconnu/)
  })

  it('« Ma config » s’ouvre depuis le dashboard et y revient', async () => {
    window.history.replaceState(null, '', '/alice')
    render(<App />)

    ;(await screen.findByRole('button', { name: 'Ma config' })).click()
    expect(window.location.pathname).toBe('/alice/config')
    ;(await screen.findByRole('button', { name: '← Retour au dashboard' })).click()
    expect(window.location.pathname).toBe('/alice')
  })

  it('l’ancienne adresse de « Mes clés » mène à « Ma config »', async () => {
    window.history.replaceState(null, '', '/alice/cles')
    render(<App />)

    await screen.findByRole('button', { name: '← Retour au dashboard' })
    expect(window.location.pathname).toBe('/alice/config')
  })
  it('passe l’onglet de l’adresse au dashboard', async () => {
    window.history.replaceState(null, '', '/alice/admin')
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        new Response(
          JSON.stringify({
            ...dashboard('alice', 'Alice'),
            tabs: [
              { slug: 'accueil', title: 'Accueil' },
              { slug: 'admin', title: 'Admin' },
            ],
            sections: [
              { title: 'Au quotidien', columns: 4, tab: 'accueil', cards: [] },
              { title: 'Machines', columns: 4, tab: 'admin', cards: [] },
            ],
          }),
          { status: 200 },
        ),
      ),
    )
    render(<App />)

    await screen.findByText('Machines')
    expect(screen.queryByText('Au quotidien')).toBeNull()
    ;(await screen.findByRole('link', { name: 'Accueil' })).click()
    await screen.findByText('Au quotidien')
    expect(window.location.pathname).toBe('/alice')
  })
})
