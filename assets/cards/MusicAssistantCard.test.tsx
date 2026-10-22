import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { MusicAssistantCard, type MusicAssistantData, type MusicAssistantProps, type SpeakerItem } from './MusicAssistantCard'

const card: CardView<MusicAssistantProps> = { id: 'm', type: 'music-assistant', title: null, span: 2, rows: 2, refresh: 300, props: { title: 'Music Assistant', service: 'Music Assistant', url: 'https://musique.example', icon: 'sh-music-assistant' } }

const data: MusicAssistantData = {
  recent: [
    { name: 'Titre récent', type: 'track', image: 'https://musique.example/imageproxy/r1' },
    { name: 'Artiste fictif', type: 'artist', image: null },
  ],
}
const speakers: SpeakerItem[] = [
  { id: 'p1', name: 'Salon', state: 'playing', title: 'Chanson fictive', artist: 'Groupe fictif', album: 'Album', image: 'https://musique.example/imageproxy/a1', duration: 200, elapsed: 70, accent: '#c14822' },
  { id: 'p2', name: 'Chambre', state: 'paused', title: 'Comptine', artist: null, album: null, image: null, duration: 100, elapsed: 30, accent: null },
  { id: 'p3', name: 'Cuisine', state: 'idle' },
  { id: 'p4', name: 'Garage', state: 'idle' },
]
const ok = <T,>(d: T): CardResult<T> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')
const renderCard = (s: SpeakerItem[] | null = speakers) => render(<MusicAssistantCard card={card} result={ok(data)} feeds={{ speakers: s === null ? null : ok({ speakers: s }) }} />)

describe('MusicAssistantCard', () => {
  it('enceintes qui jouent : titre, artiste, enceinte, position', () => {
    renderCard()

    const rows = screen.getByRole('region', { name: 'En cours de lecture' }).querySelectorAll(':scope > div')
    expect(rows).toHaveLength(2)
    expect(text(rows[0]!)).toContain('Chanson fictive')
    expect(text(rows[0]!)).toContain('Groupe fictif')
    expect(text(rows[0]!)).toContain('Salon')
    expect(text(rows[0]!)).toContain('1:10 / 3:20')
    expect(screen.getByRole('progressbar', { name: 'Chanson fictive : 35 %' })).toBeTruthy()
  })

  it('teinte de la pochette ; pause signalée', () => {
    renderCard()

    const rows = screen.getByRole('region', { name: 'En cours de lecture' }).querySelectorAll(':scope > div')
    expect((rows[0] as HTMLElement).style.getPropertyValue('--accent')).toBe('#c14822')
    expect(rows[1]!.querySelector('[aria-label="En pause"]')).not.toBeNull()
  })

  it('enceintes en veille sur une ligne', () => {
    renderCard()

    expect(text(screen.getByText(/^En veille/))).toBe('En veille : Cuisine, Garage')
  })

  it('rien ne joue : pas de section de lecture', () => {
    renderCard([speakers[2]!])

    expect(screen.queryByRole('region', { name: 'En cours de lecture' })).toBeNull()
  })

  it('écoutes récentes en slider, type en sous-titre', () => {
    renderCard()

    const list = screen.getByRole('list', { name: 'Écoutés récemment' })
    expect(list.querySelector('li')?.className).toContain('aspect-square')
    screen.getByRole('link', { name: 'Titre récent (Titre)' })
    screen.getByRole('link', { name: 'Artiste fictif (Artiste)' })
  })

  it('annonce le chargement', () => {
    const { container } = render(<MusicAssistantCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
