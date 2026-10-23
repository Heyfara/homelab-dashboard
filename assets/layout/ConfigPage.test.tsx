import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ConfigPage } from './ConfigPage'

type Call = { url: string; method: string; body: string | null }

const gw2 = { id: 'gw2', label: 'Guild Wars 2', help: 'Créer une clé sur account.arena.net.', configured: false, account: null, updatedAt: null }

type Weather = { place: string | null; latitude: number | null; longitude: number | null; custom: boolean; updatedAt: string | null }
const maison: Weather = { place: 'Maison', latitude: 45, longitude: 5, custom: false, updatedAt: null }
const places = [
  { name: 'Lyon', region: 'Rhône', country: 'France', latitude: 45.74846, longitude: 4.84671 },
  { name: 'Lyon', region: 'West Virginia', country: 'United States', latitude: 38.8673, longitude: -79.34171 },
]

function mockApi(state: { configured: boolean; account: string | null }, onPut: (key: string) => Response, config: { gw2?: boolean; weather?: Weather | null } = {}) {
  const calls: Call[] = []
  let weather = config.weather === undefined ? null : config.weather
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init: RequestInit = {}) => {
      const method = init.method ?? 'GET'
      calls.push({ url, method, body: (init.body as string) ?? null })
      if (url === '/api/profiles/zoe/config/weather') {
        if (method === 'DELETE') {
          weather = maison
          return new Response(null, { status: 204 })
        }
        const body = JSON.parse(init.body as string)
        if (body.latitude > 90) return Response.json({ error: 'Latitude attendue : un nombre entre -90 et 90.' }, { status: 422 })
        weather = { place: body.place, latitude: Math.round(body.latitude * 100) / 100, longitude: Math.round(body.longitude * 100) / 100, custom: true, updatedAt: '2026-10-24T10:00:00+02:00' }
        return Response.json(weather)
      }
      if (url.startsWith('/api/places?q=')) return Response.json(places)
      if (method === 'PUT') {
        const response = onPut(JSON.parse(init.body as string).key)
        if (response.ok) Object.assign(state, { configured: true, account: 'Zoe.1234' })
        return response
      }
      if (method === 'DELETE') {
        Object.assign(state, { configured: false, account: null })
        return new Response(null, { status: 204 })
      }
      if (url === '/api/dashboards/zoe') return Response.json({ profile: { slug: 'zoe', name: 'Zoe', color: 'emerald' }, settings: {}, sections: [] })
      return Response.json({ credentials: config.gw2 === false ? [] : [{ ...gw2, ...state }], weather })
    }),
  )
  return calls
}

const ok = () => Response.json({ configured: true, account: 'Zoe.1234', updatedAt: null })
const service = () => screen.getByRole('region', { name: 'Guild Wars 2' })

describe('ConfigPage', () => {
  beforeEach(() => window.history.replaceState(null, '', '/zoe/config'))
  afterEach(() => vi.unstubAllGlobals())

  it('liste les services, avec l’aide pour créer la clé', async () => {
    mockApi({ configured: false, account: null }, ok)
    render(<ConfigPage slug="zoe" onBack={() => {}} />)

    await screen.findByText('Pas encore de clé.')
    expect(within(service()).getByText('Créer une clé sur account.arena.net.')).toBeTruthy()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Ma config · Zoe')
  })

  it('enregistre la clé (champ masqué), puis affiche le compte vérifié et vide le champ', async () => {
    const calls = mockApi({ configured: false, account: null }, ok)
    render(<ConfigPage slug="zoe" onBack={() => {}} />)

    const input = await screen.findByLabelText('Clé Guild Wars 2')
    expect(input.getAttribute('type')).toBe('password')
    expect(input.getAttribute('autocomplete')).toBe('off')
    fireEvent.change(input, { target: { value: 'MA-CLE' } })
    fireEvent.click(within(service()).getByRole('button', { name: 'Enregistrer' }))

    await within(service()).findByText(/Configurée · compte Zoe\.1234/)
    expect(calls.find((c) => c.method === 'PUT')).toMatchObject({ url: '/api/profiles/zoe/credentials/gw2', body: JSON.stringify({ key: 'MA-CLE' }) })
    expect((screen.getByLabelText('Clé Guild Wars 2') as HTMLInputElement).value).toBe('')
  })

  it('clé refusée : la raison est affichée, rien n’est enregistré', async () => {
    mockApi({ configured: false, account: null }, () => Response.json({ error: 'Il manque des droits à cette clé : wallet.' }, { status: 422 }))
    render(<ConfigPage slug="zoe" onBack={() => {}} />)

    fireEvent.change(await screen.findByLabelText('Clé Guild Wars 2'), { target: { value: 'MAUVAISE' } })
    fireEvent.click(within(service()).getByRole('button', { name: 'Enregistrer' }))

    expect((await within(service()).findByRole('alert')).textContent).toBe('Il manque des droits à cette clé : wallet.')
    expect(within(service()).getByText('Pas encore de clé.')).toBeTruthy()
  })

  it('supprimer demande une confirmation', async () => {
    const calls = mockApi({ configured: true, account: 'Zoe.1234' }, ok)
    render(<ConfigPage slug="zoe" onBack={() => {}} />)

    fireEvent.click(await within(await screen.findByRole('region', { name: 'Guild Wars 2' })).findByRole('button', { name: 'Supprimer' }))
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false)
    fireEvent.click(within(service()).getByRole('button', { name: 'Confirmer la suppression' }))

    await within(service()).findByText('Pas encore de clé.')
    expect(calls.some((c) => c.method === 'DELETE' && c.url === '/api/profiles/zoe/credentials/gw2')).toBe(true)
  })

  it('rien à configurer : le dit, sans section', async () => {
    mockApi({ configured: false, account: null }, ok, { gw2: false })
    render(<ConfigPage slug="zoe" onBack={() => {}} />)

    await screen.findByText('Aucune de tes cartes n’a de réglage personnel.')
    expect(screen.queryByRole('region')).toBeNull()
  })

  it('météo : montre le lieu commun tant qu’aucun n’est choisi', async () => {
    mockApi({ configured: false, account: null }, ok, { gw2: false, weather: maison })
    render(<ConfigPage slug="zoe" onBack={() => {}} />)

    const section = await screen.findByRole('region', { name: 'Météo' })
    expect(within(section).getByText('Lieu par défaut : Maison')).toBeTruthy()
    expect(within(section).queryByRole('button', { name: 'Revenir au lieu par défaut' })).toBeNull()
  })

  it('météo : cherche une ville, la choisit, puis peut revenir au lieu par défaut', async () => {
    const calls = mockApi({ configured: false, account: null }, ok, { gw2: false, weather: maison })
    render(<ConfigPage slug="zoe" onBack={() => {}} />)

    const section = await screen.findByRole('region', { name: 'Météo' })
    fireEvent.change(within(section).getByLabelText('Chercher une ville'), { target: { value: 'Lyon' } })
    fireEvent.click(within(section).getByRole('button', { name: 'Chercher' }))
    fireEvent.click(await within(section).findByRole('button', { name: 'Lyon · Rhône, France' }))

    await within(section).findByText(/Lieu choisi : Lyon/)
    expect(calls.find((c) => c.url.startsWith('/api/places'))?.url).toBe('/api/places?q=Lyon')
    expect(calls.find((c) => c.method === 'PUT')?.body).toBe(JSON.stringify({ place: 'Lyon', latitude: 45.74846, longitude: 4.84671 }))
    expect(within(section).queryByRole('button', { name: /West Virginia/ })).toBeNull()

    fireEvent.click(within(section).getByRole('button', { name: 'Revenir au lieu par défaut' }))
    await within(section).findByText('Lieu par défaut : Maison')
    expect(calls.some((c) => c.method === 'DELETE' && c.url === '/api/profiles/zoe/config/weather')).toBe(true)
  })

  it('météo : coordonnées saisies à la main, erreur du serveur affichée', async () => {
    mockApi({ configured: false, account: null }, ok, { gw2: false, weather: { place: null, latitude: null, longitude: null, custom: false, updatedAt: null } })
    render(<ConfigPage slug="zoe" onBack={() => {}} />)

    const section = await screen.findByRole('region', { name: 'Météo' })
    expect(within(section).getByText('Pas encore de lieu.')).toBeTruthy()
    fireEvent.change(within(section).getByLabelText('Nom du lieu'), { target: { value: 'Chalet' } })
    fireEvent.change(within(section).getByLabelText('Latitude'), { target: { value: '145,9' } })
    fireEvent.change(within(section).getByLabelText('Longitude'), { target: { value: '6.87' } })
    fireEvent.click(within(section).getByRole('button', { name: 'Enregistrer ces coordonnées' }))

    expect((await within(section).findByRole('alert')).textContent).toBe('Latitude attendue : un nombre entre -90 et 90.')

    fireEvent.change(within(section).getByLabelText('Latitude'), { target: { value: '45,92' } })
    fireEvent.click(within(section).getByRole('button', { name: 'Enregistrer ces coordonnées' }))
    await within(section).findByText(/Lieu choisi : Chalet/)
  })

  it('bouton de retour au dashboard', async () => {
    mockApi({ configured: false, account: null }, ok)
    const onBack = vi.fn()
    render(<ConfigPage slug="zoe" onBack={onBack} />)

    fireEvent.click(await screen.findByRole('button', { name: '← Retour au dashboard' }))
    await waitFor(() => expect(onBack).toHaveBeenCalled())
  })
})
