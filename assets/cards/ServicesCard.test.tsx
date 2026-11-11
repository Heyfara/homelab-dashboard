import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { ServicesCard, type ServicesData, type ServicesProps } from './ServicesCard'
import type { ServiceData } from './serviceStatus'

const card: CardView<ServicesProps> = {
  id: 'tom-services-abc',
  type: 'services',
  title: null,
  span: 2,
  rows: 2,
  refresh: 30,
  props: {
    services: [
      { id: 'recettes', name: 'Recettes', description: 'Mealie', url: 'https://recettes.example', icon: 'sh-mealie' },
      { id: 'photos', name: 'Photos', description: null, url: 'https://photos.example', icon: 'sh-immich' },
      { id: 'films', name: 'Films', description: null, url: 'https://films.example', icon: null },
      { id: 'note', name: 'Note', description: null, url: null, icon: null },
    ],
  },
}

const running: ServiceData = { container: { status: 'running', health: null, uptime: null, cpu: null, memory: null }, probe: { up: true, statusCode: 200, latencyMs: 5, error: null } }
const ok = (status: Record<string, ServiceData>): CardResult<ServicesData> => ({ status: 'ok', data: { status }, error: null, fetchedAt: '2026-11-11T10:00:00+00:00' })
// Nom de chaque service, dans l'ordre affiché (l'icône de repli contient l'initiale : on lit le titre).
const names = () => within(screen.getByRole('list')).getAllByRole('listitem').map((li) => li.querySelector('[title]')!.getAttribute('title')!.split(' : ')[0])

describe('ServicesCard', () => {
  it('une icône par service, lien vers son adresse, dans l’ordre du YAML', () => {
    render(<ServicesCard card={card} result={ok({ recettes: running, photos: running, films: running, note: { container: null, probe: null } })} />)

    expect(names()).toEqual(['Recettes', 'Photos', 'Films', 'Note'])
    expect(screen.getByRole('link', { name: /Recettes/ }).getAttribute('href')).toBe('https://recettes.example')
    expect(screen.queryByRole('link', { name: /Note/ })).toBeNull() // sans adresse : pas de lien
    screen.getByRole('heading', { name: 'Services' })
    screen.getByText('4 services')
    screen.getByText('Tout fonctionne')
  })

  it('l’état de chaque service est lisible au survol et par les lecteurs d’écran', () => {
    render(<ServicesCard card={card} result={ok({ recettes: running, photos: running, films: running, note: { container: null, probe: null } })} />)

    const link = screen.getByRole('link', { name: 'Recettes : en ligne' })
    expect(link.getAttribute('title')).toBe('Recettes : en ligne')
  })

  it('les services en panne passent en tête, et sont comptés', () => {
    render(
      <ServicesCard
        card={card}
        result={ok({
          recettes: running,
          photos: running,
          films: { container: null, probe: { up: false, statusCode: null, latencyMs: 3000, error: 'timeout' } },
          note: { container: null, probe: null },
        })}
      />,
    )

    expect(names()[0]).toBe('Films')
    screen.getByRole('link', { name: 'Films : ne répond pas' })
    screen.getByText('1 problème')
  })

  it('avant la première réponse : ordre du YAML, en vérification', () => {
    render(<ServicesCard card={{ ...card, title: 'Outils' }} result={null} />)

    expect(names()).toEqual(['Recettes', 'Photos', 'Films', 'Note'])
    screen.getByRole('heading', { name: 'Outils' })
    screen.getByRole('link', { name: 'Recettes : vérification…' })
  })

  it('répartit les icônes sur autant de lignes que la carte a de rangées', () => {
    render(<ServicesCard card={card} result={null} />) // 4 services, rows: 2 → 2 colonnes
    expect(screen.getByRole('list').style.getPropertyValue('--cols')).toBe('2')

    render(<ServicesCard card={{ ...card, id: 'x', rows: 1 }} result={null} />) // rows: 1 → une seule ligne de 4
    expect(screen.getAllByRole('list')[1]!.style.getPropertyValue('--cols')).toBe('4')
  })

  it('accorde le compteur au singulier', () => {
    render(<ServicesCard card={{ ...card, props: { services: card.props.services.slice(0, 1) } }} result={null} />)

    screen.getByText('1 service')
  })
})
