import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { ImmichCard, type ImmichData, type ImmichProps } from './ImmichCard'

const card: CardView<ImmichProps> = { id: 'i', type: 'immich', title: null, span: 2, rows: 2, refresh: 300, props: { title: 'Immich', service: 'Immich', url: 'https://photos.example', icon: 'sh-immich' } }

const data: ImmichData = {
  stats: { images: 1520, videos: 87 },
  memories: [
    { id: 'mem-2023', year: 2023, yearsAgo: 3, count: 2, cover: '/api/cards/i/image?path=p2%2Fpreview&sig=x', link: 'https://photos.example/memories/mem-2023' },
    { id: 'mem-2025', year: 2025, yearsAgo: 1, count: 1, cover: '/api/cards/i/image?path=p3%2Fpreview&sig=y', link: 'https://photos.example/memories/mem-2025' },
  ],
  recent: [
    { id: 'p1', video: false, takenAt: '2026-10-19T16:42:10', thumb: '/api/cards/i/image?path=p1%2Fthumbnail&sig=a', link: 'https://photos.example/photos/p1' },
    { id: 'v1', video: true, takenAt: '2026-10-18T10:00:00', thumb: '/api/cards/i/image?path=v1%2Fthumbnail&sig=b', link: 'https://photos.example/photos/v1' },
  ],
}
const result = (d: ImmichData): CardResult<ImmichData> => ({ status: 'ok', data: d, error: null, fetchedAt: '' })
const text = (el: Element) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('ImmichCard', () => {
  it('souvenirs : couverture relayée, « il y a N ans », nombre de photos', () => {
    render(<ImmichCard card={card} result={result(data)} />)

    const links = screen.getByRole('region', { name: 'Souvenirs' }).querySelectorAll('a')
    expect(links).toHaveLength(2)
    expect(links[0]!.getAttribute('href')).toBe('https://photos.example/memories/mem-2023')
    expect(text(links[0]!)).toContain('Il y a 3 ans')
    expect(text(links[0]!)).toContain('2 photos')
    expect(text(links[1]!)).toContain('Il y a 1 an')
    expect(links[0]!.querySelector('img')?.getAttribute('src')).toBe(data.memories[0]!.cover)
  })

  it('pas de souvenir : pas de section', () => {
    render(<ImmichCard card={card} result={result({ ...data, memories: [] })} />)

    expect(screen.queryByRole('region', { name: 'Souvenirs' })).toBeNull()
  })

  it('dernières photos en slider, vidéos signalées', () => {
    render(<ImmichCard card={card} result={result(data)} />)

    const list = screen.getByRole('list', { name: 'Dernières photos' })
    const [photo, video] = list.querySelectorAll('a')
    expect(photo!.getAttribute('href')).toBe('https://photos.example/photos/p1')
    expect(photo!.getAttribute('aria-label')).toBe('Photo du 19 octobre 2026')
    expect(photo!.querySelector('img')?.getAttribute('src')).toBe(data.recent[0]!.thumb)
    expect(video!.getAttribute('aria-label')).toBe('Vidéo du 18 octobre 2026')
  })

  it('compte les photos et vidéos', () => {
    render(<ImmichCard card={card} result={result(data)} />)

    screen.getByText('1 520 photos · 87 vidéos', { normalizer: (s) => s.replace(/[  ]/g, ' ') })
  })

  it('annonce le chargement', () => {
    const { container } = render(<ImmichCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
