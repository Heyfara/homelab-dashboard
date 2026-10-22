import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { ServiceCard, type ServiceProps } from './ServiceCard'
import type { ServiceData } from './serviceStatus'

const card: CardView<ServiceProps> = {
  id: 'alice-0-0',
  type: 'service',
  title: null,
  span: 1,
  rows: 1,
  refresh: 30,
  props: { name: 'Photos', description: 'Galerie de la famille', url: 'https://photos.example', icon: 'sh-immich', host: 'alpha' },
}

const result: CardResult<ServiceData> = {
  status: 'ok',
  data: { container: { status: 'running', health: 'healthy', uptime: 'Up 7 days', cpu: 1.5, memory: 200 }, probe: { up: true, statusCode: 200, latencyMs: 8, error: null } },
  error: null,
  fetchedAt: '2026-10-18T10:00:00+00:00',
}

describe('ServiceCard', () => {
  it('affiche le service comme un lien vers son adresse', () => {
    render(<ServiceCard card={card} result={result} />)

    const link = screen.getByRole('link', { name: /Photos/ })
    expect(link.getAttribute('href')).toBe('https://photos.example')
    screen.getByText('Galerie de la famille')
    screen.getByText('En ligne')
  })

  it('utilise le titre de la carte s’il est défini', () => {
    render(<ServiceCard card={{ ...card, title: 'Nos photos' }} result={result} />)

    screen.getByRole('link', { name: /Nos photos/ })
  })

  it('affiche l’icône selfh.st', () => {
    const { container } = render(<ServiceCard card={card} result={result} />)

    expect(container.querySelector('img')?.getAttribute('src')).toBe('https://cdn.jsdelivr.net/gh/selfhst/icons/webp/immich.webp')
  })

  it('montre l’état en attente avant la première réponse', () => {
    render(<ServiceCard card={card} result={null} />)

    screen.getByText('Vérification…')
  })

  it('sans adresse, n’est pas un lien', () => {
    render(<ServiceCard card={{ ...card, props: { ...card.props, url: null } }} result={result} />)

    expect(screen.queryByRole('link')).toBeNull()
    screen.getByText('Photos')
  })
})
