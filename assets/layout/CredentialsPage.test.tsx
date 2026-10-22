import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CredentialsPage } from './CredentialsPage'

type Call = { url: string; method: string; body: string | null }

const gw2 = { id: 'gw2', label: 'Guild Wars 2', help: 'Créer une clé sur account.arena.net.', configured: false, account: null, updatedAt: null }

function mockApi(state: { configured: boolean; account: string | null }, onPut: (key: string) => Response) {
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init: RequestInit = {}) => {
      const method = init.method ?? 'GET'
      calls.push({ url, method, body: (init.body as string) ?? null })
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
      return Response.json([{ ...gw2, ...state }])
    }),
  )
  return calls
}

const ok = () => Response.json({ configured: true, account: 'Zoe.1234', updatedAt: null })
const service = () => screen.getByRole('region', { name: 'Guild Wars 2' })

describe('CredentialsPage', () => {
  beforeEach(() => window.history.replaceState(null, '', '/zoe/cles'))
  afterEach(() => vi.unstubAllGlobals())

  it('liste les services, avec l’aide pour créer la clé', async () => {
    mockApi({ configured: false, account: null }, ok)
    render(<CredentialsPage slug="zoe" onBack={() => {}} />)

    await screen.findByText('Pas encore de clé.')
    expect(within(service()).getByText('Créer une clé sur account.arena.net.')).toBeTruthy()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Mes clés · Zoe')
  })

  it('enregistre la clé (champ masqué), puis affiche le compte vérifié et vide le champ', async () => {
    const calls = mockApi({ configured: false, account: null }, ok)
    render(<CredentialsPage slug="zoe" onBack={() => {}} />)

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
    render(<CredentialsPage slug="zoe" onBack={() => {}} />)

    fireEvent.change(await screen.findByLabelText('Clé Guild Wars 2'), { target: { value: 'MAUVAISE' } })
    fireEvent.click(within(service()).getByRole('button', { name: 'Enregistrer' }))

    expect((await within(service()).findByRole('alert')).textContent).toBe('Il manque des droits à cette clé : wallet.')
    expect(within(service()).getByText('Pas encore de clé.')).toBeTruthy()
  })

  it('supprimer demande une confirmation', async () => {
    const calls = mockApi({ configured: true, account: 'Zoe.1234' }, ok)
    render(<CredentialsPage slug="zoe" onBack={() => {}} />)

    fireEvent.click(await within(await screen.findByRole('region', { name: 'Guild Wars 2' })).findByRole('button', { name: 'Supprimer' }))
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false)
    fireEvent.click(within(service()).getByRole('button', { name: 'Confirmer la suppression' }))

    await within(service()).findByText('Pas encore de clé.')
    expect(calls.some((c) => c.method === 'DELETE' && c.url === '/api/profiles/zoe/credentials/gw2')).toBe(true)
  })

  it('bouton de retour au dashboard', async () => {
    mockApi({ configured: false, account: null }, ok)
    const onBack = vi.fn()
    render(<CredentialsPage slug="zoe" onBack={onBack} />)

    fireEvent.click(await screen.findByRole('button', { name: '← Retour au dashboard' }))
    await waitFor(() => expect(onBack).toHaveBeenCalled())
  })
})
