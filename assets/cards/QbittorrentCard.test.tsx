import { act, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { QbittorrentCard, type QbittorrentData, type QbittorrentProps } from './QbittorrentCard'

const card: CardView<QbittorrentProps> = {
  id: 'tom-2-0',
  type: 'qbittorrent',
  title: null,
  span: 2,
  rows: 2,
  refresh: 30,
  props: { title: 'Téléchargements', service: 'qBittorrent', url: 'https://torrents.example', icon: 'sh-qbittorrent' },
}

const data: QbittorrentData = {
  totals: { downloaded: 5_000_000_000_000, uploaded: 12_000_000_000_000, ratio: 2.4, downloadSpeed: 1_500_000, uploadSpeed: 250_000 },
  torrents: [
    { name: 'film.mkv', size: 4_000_000_000, progress: 0.42, status: 'downloading', addedAt: '2026-10-19T10:00:00+00:00', completedAt: null },
    { name: 'debian-13.iso', size: 700_000_000, progress: 1, status: 'seeding', addedAt: '2026-10-10T10:00:00+00:00', completedAt: '2026-10-10T10:10:00+00:00' },
  ],
}

const result = (d: QbittorrentData, fetchedAt = '2026-10-19T12:00:00+00:00'): CardResult<QbittorrentData> => ({ status: 'ok', data: d, error: null, fetchedAt })
const text = (el: HTMLElement) => (el.textContent ?? '').replace(/[  ]/g, ' ')

describe('QbittorrentCard', () => {
  it('affiche une tuile par sens avec le total et le débit actuel', () => {
    render(<QbittorrentCard card={card} result={result(data)} />)

    const received = screen.getByRole('group', { name: 'Reçu' })
    expect(text(received)).toContain('5 To')
    expect(text(received)).toContain('1,5 Mo/s')
    const sent = screen.getByRole('group', { name: 'Envoyé' })
    expect(text(sent)).toContain('12 To')
    expect(text(sent)).toContain('250 Ko/s')
  })

  it('répartit reçu et envoyé sur une barre, avec le ratio', () => {
    const { container } = render(<QbittorrentCard card={card} result={result(data)} />)

    const bar = screen.getByRole('img', { name: /Ratio 2,4/ })
    const [down, up] = Array.from(bar.children) as HTMLElement[]
    expect(down!.style.flexGrow).toBe(String(5_000_000_000_000))
    expect(up!.style.flexGrow).toBe(String(12_000_000_000_000))
    expect(text(container)).toContain('×2,4')
  })

  it('accumule les débits pour tracer leur courbe', () => {
    const { rerender, container } = render(<QbittorrentCard card={card} result={result(data, 't1')} />)
    expect(container.querySelector('polyline')).toBeNull()
    expect(container.querySelectorAll('line[stroke-dasharray]')).toHaveLength(2)

    act(() => rerender(<QbittorrentCard card={card} result={result({ ...data, totals: { ...data.totals, downloadSpeed: 3_000_000 } }, 't2')} />))

    expect(container.querySelectorAll('polyline')).toHaveLength(2)
  })

  it('liste les derniers téléchargements avec leur état', () => {
    render(<QbittorrentCard card={card} result={result(data)} />)

    const rows = screen.getAllByRole('listitem')
    expect(rows).toHaveLength(2)
    expect(text(rows[0]!)).toContain('film.mkv')
    expect(text(rows[0]!)).toContain('42 %')
    expect(text(rows[1]!)).toContain('Partage')
    expect(rows[1]!.querySelector('[role="progressbar"]')).toBeNull()
    expect(rows[1]!.querySelector('svg[aria-hidden="true"]')).not.toBeNull()
    expect(text(rows[1]!)).toContain('700 Mo')
  })

  it('montre la progression d’un téléchargement en cours', () => {
    render(<QbittorrentCard card={card} result={result(data)} />)

    const bar = screen.getByRole('progressbar', { name: 'film.mkv' })
    expect(bar.getAttribute('aria-valuenow')).toBe('42')
  })

  it('lien vers qBittorrent', () => {
    render(<QbittorrentCard card={card} result={result(data)} />)

    expect(screen.getByRole('link', { name: /Ouvrir qBittorrent/ }).getAttribute('href')).toBe('https://torrents.example')
  })

  it('sans téléchargement', () => {
    render(<QbittorrentCard card={card} result={result({ ...data, torrents: [] })} />)

    screen.getByText('Aucun téléchargement')
  })

  it('annonce le chargement', () => {
    const { container } = render(<QbittorrentCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
  })
})
