import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { UrssafCard, sessionView, type UrssafData, type UrssafProps, type UrssafRun } from './UrssafCard'

const card: CardView<UrssafProps> = {
  id: 'urssaf',
  type: 'urssaf',
  title: null,
  span: 4,
  rows: 6,
  refresh: 5,
  props: { title: 'Déclaration Urssaf', taskUrl: 'https://taches.example/dags/declaration', test: false },
}

const running: UrssafRun = {
  id: 'run-2',
  state: 'running',
  startedAt: '2026-11-16T18:00:00+01:00',
  finishedAt: null,
  amount: 120,
  test: false,
  step: 'rempli',
  message: 'Montant 120 € saisi. Vérifie puis clique sur « Valider » dans l’écran.',
  screenUrl: 'https://ecran.example/vnc.html?autoconnect=1&password=ab12cd34',
}
const deadline = { period: '4e trimestre 2026', opens: '2027-01-01', due: '2027-01-31', open: false }
const result = (d: UrssafData): CardResult<UrssafData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

afterEach(() => vi.restoreAllMocks())

describe('sessionView', () => {
  it('session en cours : étape lisible', () => {
    expect(sessionView(running)).toEqual({ active: true, tone: 'warn', label: 'à vérifier et valider' })
    expect(sessionView({ ...running, step: 'connexion' })).toEqual({ active: true, tone: 'unknown', label: 'préparation…' })
    expect(sessionView({ ...running, step: 'formulaire-introuvable' })).toEqual({ active: true, tone: 'warn', label: 'formulaire indisponible' })
    expect(sessionView({ ...running, step: 'envoyée' })).toEqual({ active: true, tone: 'ok', label: 'déclaration envoyée' })
    expect(sessionView({ ...running, state: 'queued', step: null })).toEqual({ active: true, tone: 'unknown', label: 'préparation…' })
  })

  it('session terminée : envoyée ou non', () => {
    const done = { ...running, finishedAt: '2026-11-16T18:10:00+01:00', screenUrl: null }
    expect(sessionView({ ...done, state: 'ok', step: 'envoyée' })).toEqual({ active: false, tone: 'ok', label: 'déclaration envoyée' })
    expect(sessionView({ ...done, state: 'ok', test: true, step: 'rempli' })).toEqual({ active: false, tone: 'ok', label: 'test réussi' })
    expect(sessionView({ ...done, state: 'failed' })).toEqual({ active: false, tone: 'down', label: 'non envoyée' })
    expect(sessionView(null)).toEqual({ active: false, tone: 'unknown', label: 'jamais lancée' })
  })
})

describe('UrssafCard', () => {
  it('sans session : montant prérempli à 0, échéance et bouton', () => {
    render(<UrssafCard card={card} result={result({ run: null, deadline })} />)

    expect((screen.getByLabelText('Chiffre d’affaires (prestations de services)') as HTMLInputElement).value).toBe('0')
    expect(text(screen.getByText('4e trimestre 2026').closest('p')!)).toContain('à déclarer du 1 janv. 2027 au 31 janv. 2027')
    expect((screen.getByRole('button', { name: 'Préparer la déclaration' }) as HTMLButtonElement).disabled).toBe(false)
  })

  it('lance la session avec le montant saisi, recharge la carte et ouvre l’écran en grand', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ runId: 'run-3' }), { status: 200 }))
    const reload = vi.fn()
    render(<UrssafCard card={card} result={result({ run: null, deadline })} reload={reload} />)

    fireEvent.change(screen.getByLabelText('Chiffre d’affaires (prestations de services)'), { target: { value: '1250' } })
    fireEvent.click(screen.getByRole('button', { name: 'Préparer la déclaration' }))

    await waitFor(() => expect(reload).toHaveBeenCalled())
    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toBe('/api/cards/urssaf/actions/start')
    expect(JSON.parse(String(init?.body))).toEqual({ amount: 1250 })
    // En attendant le passage Dagu, la fenêtre annonce la préparation
    expect(screen.getByRole('dialog', { name: 'Déclaration Urssaf' }).textContent).toContain('Préparation de la session')
  })

  it('refuse un montant qui n’est pas un entier positif', () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
    render(<UrssafCard card={card} result={result({ run: null, deadline })} />)

    fireEvent.change(screen.getByLabelText('Chiffre d’affaires (prestations de services)'), { target: { value: '12.5' } })

    expect((screen.getByRole('button', { name: 'Préparer la déclaration' }) as HTMLButtonElement).disabled).toBe(true)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('montre la raison d’un refus du serveur', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ error: 'La tâche declaration tourne déjà.' }), { status: 409 }))
    render(<UrssafCard card={card} result={result({ run: null, deadline })} />)

    fireEvent.click(screen.getByRole('button', { name: 'Préparer la déclaration' }))

    expect((await screen.findByRole('alert')).textContent).toBe('La tâche declaration tourne déjà.')
  })

  it('session en cours : la carte reste petite, l’écran s’ouvre en grand à la demande', () => {
    render(<UrssafCard card={card} result={result({ run: running, deadline })} />)

    expect(screen.getAllByText('à vérifier et valider').length).toBeGreaterThan(0)
    expect(screen.queryByRole('button', { name: 'Préparer la déclaration' })).toBeNull()
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(screen.queryByTitle('Écran du navigateur de la déclaration')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'Afficher l’écran' }))
    const dialog = screen.getByRole('dialog', { name: 'Déclaration Urssaf' })
    expect(dialog.textContent).toContain(running.message!)
    expect(screen.getByTitle('Écran du navigateur de la déclaration').getAttribute('src')).toBe(running.screenUrl)

    // La croix (ou Échap) ne ferme que la fenêtre : la session continue
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByRole('dialog')).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Afficher l’écran' }))
    fireEvent.click(screen.getByRole('button', { name: 'Masquer l’écran' }))
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('« Fermer la session » arrête la tâche et recharge la carte', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ stopped: 'run-2' }), { status: 200 }))
    const reload = vi.fn()
    render(<UrssafCard card={card} result={result({ run: running, deadline })} reload={reload} />)

    fireEvent.click(screen.getByRole('button', { name: 'Afficher l’écran' }))
    fireEvent.click(screen.getByRole('button', { name: 'Fermer la session' }))
    await waitFor(() => expect(reload).toHaveBeenCalled())
    expect(fetchMock.mock.calls[0]![0]).toBe('/api/cards/urssaf/actions/stop')
  })

  it('signale le mode test', () => {
    render(<UrssafCard card={{ ...card, props: { ...card.props, test: true } }} result={result({ run: null, deadline: null })} />)

    expect(screen.getByText('mode test')).toBeTruthy()
  })
})
