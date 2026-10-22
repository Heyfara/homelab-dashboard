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

  it('« Mes clés » s’ouvre depuis le dashboard et y revient', async () => {
    window.history.replaceState(null, '', '/alice')
    render(<App />)

    ;(await screen.findByRole('button', { name: 'Mes clés' })).click()
    expect(window.location.pathname).toBe('/alice/cles')
    ;(await screen.findByRole('button', { name: '← Retour au dashboard' })).click()
    expect(window.location.pathname).toBe('/alice')
  })
})
