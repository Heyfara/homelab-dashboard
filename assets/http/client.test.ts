import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, deleteCredential, fetchCard, fetchConfig, fetchDashboard, fetchProfiles, resetLayout, resetWeatherLocation, saveCredential, saveLayout, saveWeatherLocation, searchPlaces } from './client'

function mockFetch(status: number, body: unknown) {
  // Une réponse neuve par appel : un corps ne se lit qu'une fois.
  const fetch = vi.fn().mockImplementation(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }))
  vi.stubGlobal('fetch', fetch)
  return fetch
}

afterEach(() => vi.unstubAllGlobals())

describe('client API', () => {
  it('lit les profils', async () => {
    const fetch = mockFetch(200, [{ slug: 'alice', name: 'Alice', color: 'indigo' }])

    await expect(fetchProfiles()).resolves.toEqual([{ slug: 'alice', name: 'Alice', color: 'indigo' }])
    expect(fetch).toHaveBeenCalledWith('/api/profiles', expect.objectContaining({ headers: { Accept: 'application/json' } }))
  })

  it('encode le profil et la carte dans l’URL', async () => {
    const fetch = mockFetch(200, {})

    await fetchDashboard('alice')
    await fetchCard('alice-0-1')

    expect(fetch.mock.calls.map((c) => c[0])).toEqual(['/api/dashboards/alice', '/api/cards/alice-0-1'])
  })

  it('demande un flux de la carte', async () => {
    const fetch = mockFetch(200, {})

    await fetchCard('tom-1-0', undefined, 'sessions')

    expect(fetch.mock.calls[0]?.[0]).toBe('/api/cards/tom-1-0?feed=sessions')
  })

  it('transmet le signal d’annulation', async () => {
    const fetch = mockFetch(200, {})
    const controller = new AbortController()

    await fetchCard('c', controller.signal)

    expect(fetch.mock.calls[0]?.[1]?.signal).toBe(controller.signal)
  })

  it('transforme une erreur de l’API en ApiError avec ses détails', async () => {
    mockFetch(503, { error: 'Configuration du dashboard invalide', details: ['Carte x : type inconnu'] })

    const error = await fetchProfiles().catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 503, message: 'Configuration du dashboard invalide', details: ['Carte x : type inconnu'] })
  })

  it('gère une réponse d’erreur qui n’est pas du JSON', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>Bad Gateway</html>', { status: 502 })))

    await expect(fetchProfiles()).rejects.toMatchObject({ status: 502, message: 'Le serveur a répondu 502' })
  })

  it('« Ma config » : lire, enregistrer une clé en JSON', async () => {
    const fetch = mockFetch(200, { configured: true, account: 'Zoe.1234', updatedAt: null })

    await fetchConfig('zoe')
    await expect(saveCredential('zoe', 'gw2', 'CLE')).resolves.toMatchObject({ account: 'Zoe.1234' })

    expect(fetch.mock.calls[0]?.[0]).toBe('/api/profiles/zoe/config')
    expect(fetch.mock.calls[1]?.[0]).toBe('/api/profiles/zoe/credentials/gw2')
    expect(fetch.mock.calls[1]?.[1]).toMatchObject({ method: 'PUT', body: JSON.stringify({ key: 'CLE' }), headers: { Accept: 'application/json', 'Content-Type': 'application/json' } })
  })

  it('« Ma config » : lieu de la météo et recherche de ville', async () => {
    const fetch = mockFetch(200, [])

    await saveWeatherLocation('zoe', { place: 'Brest', latitude: 48.39, longitude: -4.49 })
    await searchPlaces('Saint-Malo & co')

    expect(fetch.mock.calls[0]?.[0]).toBe('/api/profiles/zoe/config/weather')
    expect(fetch.mock.calls[0]?.[1]).toMatchObject({ method: 'PUT', body: JSON.stringify({ place: 'Brest', latitude: 48.39, longitude: -4.49 }), headers: { 'Content-Type': 'application/json' } })
    expect(fetch.mock.calls[1]?.[0]).toBe('/api/places?q=Saint-Malo%20%26%20co')
  })

  it('oublier le lieu choisi', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetch)

    await expect(resetWeatherLocation('zoe')).resolves.toBeUndefined()
    expect(fetch).toHaveBeenCalledWith('/api/profiles/zoe/config/weather', expect.objectContaining({ method: 'DELETE' }))
  })

  it('suppression : réponse vide acceptée', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetch)

    await expect(deleteCredential('zoe', 'gw2')).resolves.toBeUndefined()
    expect(fetch.mock.calls[0]?.[1]).toMatchObject({ method: 'DELETE' })
  })

  it('clé refusée : le message du serveur', async () => {
    mockFetch(422, { error: 'Il manque des droits à cette clé : wallet.' })

    await expect(saveCredential('zoe', 'gw2', 'CLE')).rejects.toMatchObject({ status: 422, message: 'Il manque des droits à cette clé : wallet.' })
  })

  it('enregistre l’ordre des cartes en JSON', async () => {
    const fetch = vi.fn().mockImplementation(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetch)

    await expect(saveLayout('alice', ['b', 'a'])).resolves.toBeUndefined()
    expect(fetch).toHaveBeenCalledWith('/api/profiles/alice/layout', expect.objectContaining({ method: 'PUT', body: '{"order":["b","a"]}' }))
    expect(fetch.mock.calls[0]?.[1].headers).toMatchObject({ 'Content-Type': 'application/json' })
  })

  it('revient à l’ordre par défaut', async () => {
    const fetch = vi.fn().mockImplementation(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetch)

    await expect(resetLayout('alice')).resolves.toBeUndefined()
    expect(fetch).toHaveBeenCalledWith('/api/profiles/alice/layout', expect.objectContaining({ method: 'DELETE' }))
  })
})
