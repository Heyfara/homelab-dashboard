import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { CardResult, CardView } from '../http/types'
import { JellyfinLatestCard, type JellyfinLatestData, type JellyfinLatestProps } from './JellyfinLatestCard'

const card: CardView<JellyfinLatestProps> = {
  id: 'tom-0-3',
  type: 'jellyfin-latest',
  title: null,
  span: 2,
  rows: 2,
  refresh: 300,
  props: { title: 'Derniers ajouts', service: 'Jellyfin', url: 'https://jellyfin.example', icon: 'sh-jellyfin' },
}

const result = (items: JellyfinLatestData['items']): CardResult<JellyfinLatestData> => ({
  status: 'ok',
  data: { items },
  error: null,
  fetchedAt: '2026-10-19T10:00:00+00:00',
})

const chihiro = {
  id: 'm1',
  title: 'Le Voyage de Chihiro',
  subtitle: '2001',
  image: 'https://jellyfin.example/Items/m1/Images/Primary?tag=t',
  link: 'https://jellyfin.example/web/#/details?id=m1&serverId=srv',
  addedAt: '2026-10-18T20:00:00+00:00',
}

describe('JellyfinLatestCard', () => {
  it('affiche le titre de la carte et un lien vers Jellyfin', () => {
    render(<JellyfinLatestCard card={card} result={result([])} />)

    screen.getByRole('heading', { name: 'Derniers ajouts' })
    expect(screen.getByRole('link', { name: /Ouvrir Jellyfin/ }).getAttribute('href')).toBe('https://jellyfin.example')
  })

  it('chaque ajout est une affiche qui ouvre sa fiche dans Jellyfin', () => {
    render(<JellyfinLatestCard card={card} result={result([chihiro])} />)

    const link = screen.getByRole('link', { name: 'Le Voyage de Chihiro (2001)' })
    expect(link.getAttribute('href')).toBe(chihiro.link)
    expect(link.querySelector('img')?.getAttribute('src')).toBe(chihiro.image)
    screen.getByText('Le Voyage de Chihiro')
  })

  it('sans affiche, montre le titre à la place', () => {
    render(<JellyfinLatestCard card={card} result={result([{ ...chihiro, image: null, subtitle: null }])} />)

    const link = screen.getByRole('link', { name: 'Le Voyage de Chihiro' })
    expect(link.querySelector('img')).toBeNull()
  })

  it('annonce le chargement avant la première réponse', () => {
    const { container } = render(<JellyfinLatestCard card={card} result={null} />)

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull()
    expect(screen.queryAllByRole('link', { name: /\(/ })).toHaveLength(0)
  })

  it('dit quand il n’y a rien de nouveau', () => {
    render(<JellyfinLatestCard card={card} result={result([])} />)

    screen.getByText('Rien de nouveau')
  })
})

describe('JellyfinLatestCard : lectures en cours', () => {
  const sessions = (list: unknown[]): CardResult<{ sessions: unknown[] }> => ({ status: 'ok', data: { sessions: list }, error: null, fetchedAt: '' })
  const primer = {
    id: 's1',
    user: 'Tom',
    device: 'Firefox',
    client: 'Jellyfin Web',
    title: 'Primer',
    subtitle: '2004',
    image: 'https://jellyfin.example/Items/m1/Images/Primary?tag=t1',
    link: 'https://jellyfin.example/web/#/details?id=m1&serverId=srv',
    position: 1159,
    duration: 4634,
    paused: true,
    transcoding: true,
  }

  it('montre chaque lecture : titre, qui, où, progression, pause, transcodage', () => {
    render(<JellyfinLatestCard card={card} result={result([chihiro])} feeds={{ sessions: sessions([primer]) }} />)

    const playing = screen.getByRole('region', { name: 'Lectures en cours' })
    const content = (playing.textContent ?? '').replace(/[  ]/g, ' ')
    expect(content).toContain('Primer')
    expect(content).toContain('Tom · Firefox')
    expect(content).toContain('19:19 / 1:17:14')
    expect(content).toContain('Transcodage')
    expect(screen.getByLabelText('En pause')).toBeTruthy()
    expect(screen.getByRole('progressbar', { name: /Primer/ }).getAttribute('aria-valuenow')).toBe('25')
  })

  it('rien en cours : pas de section', () => {
    render(<JellyfinLatestCard card={card} result={result([chihiro])} feeds={{ sessions: sessions([]) }} />)

    expect(screen.queryByRole('region', { name: 'Lectures en cours' })).toBeNull()
  })
})

describe('JellyfinLatestCard : nombre de lectures et slider', () => {
  const session = (n: number) => ({
    id: `s${n}`,
    user: `Personne ${n}`,
    device: 'Salon',
    client: 'Android TV',
    title: `Film ${n}`,
    subtitle: null,
    image: null,
    link: '#',
    position: 10,
    duration: 100,
    paused: false,
    transcoding: false,
  })
  const feeds = (n: number) => ({ sessions: { status: 'ok' as const, data: { sessions: Array.from({ length: n }, (_, i) => session(i + 1)) }, error: null, fetchedAt: '' } })

  it('autant de lectures que de rangées, puis « +N autres lectures » avec les noms au survol', () => {
    render(<JellyfinLatestCard card={card} result={result([chihiro])} feeds={feeds(4)} />)

    const region = screen.getByRole('region', { name: 'Lectures en cours' })
    expect(region.querySelectorAll('a')).toHaveLength(2)
    const more = screen.getByText('+2 autres lectures')
    expect(more.getAttribute('title')).toBe('Personne 3 : Film 3, Personne 4 : Film 4')
  })

  it('une carte plus haute montre plus de lectures', () => {
    render(<JellyfinLatestCard card={{ ...card, rows: 3 }} result={result([chihiro])} feeds={feeds(3)} />)

    expect(screen.getByRole('region', { name: 'Lectures en cours' }).querySelectorAll('a')).toHaveLength(3)
    expect(screen.queryByText(/autres? lectures?/)).toBeNull()
  })

  it('une seule lecture en trop : singulier', () => {
    render(<JellyfinLatestCard card={card} result={result([chihiro])} feeds={feeds(3)} />)

    screen.getByText('+1 autre lecture')
  })

  it('slider : toutes les affiches sont présentes, les flèches font défiler', () => {
    const scrollBy = vi.fn()
    HTMLElement.prototype.scrollBy = scrollBy
    const many = Array.from({ length: 10 }, (_, i) => ({ ...chihiro, id: `m${i}`, title: `Film ${i}`, link: `#${i}` }))
    render(<JellyfinLatestCard card={card} result={result(many)} />)

    const list = screen.getByRole('list', { name: 'Derniers ajouts' })
    expect(list.querySelectorAll('li')).toHaveLength(10)

    // Mise en page simulée : 4 affiches visibles sur 10, au début.
    Object.defineProperties(list, { scrollWidth: { value: 1000, configurable: true }, clientWidth: { value: 400, configurable: true }, scrollLeft: { value: 0, writable: true, configurable: true } })
    fireEvent.scroll(list)

    const next = screen.getByRole('button', { name: 'Ajouts suivants' })
    const previous = screen.getByRole('button', { name: 'Ajouts précédents' })
    expect(next.hasAttribute('disabled')).toBe(false)
    expect(previous.hasAttribute('disabled')).toBe(true)

    fireEvent.click(next)
    expect(scrollBy).toHaveBeenCalledWith({ left: 360, behavior: 'smooth' })

    list.scrollLeft = 600
    fireEvent.scroll(list)
    expect(next.hasAttribute('disabled')).toBe(true)
    expect(previous.hasAttribute('disabled')).toBe(false)
  })

  it('slider : glisser à la souris fait défiler, sans ouvrir l’affiche', () => {
    const many = Array.from({ length: 10 }, (_, i) => ({ ...chihiro, id: `m${i}`, title: `Film ${i}`, link: `#${i}` }))
    render(<JellyfinLatestCard card={card} result={result(many)} />)
    const list = screen.getByRole('list', { name: 'Derniers ajouts' })
    Object.defineProperty(list, 'scrollLeft', { value: 0, writable: true, configurable: true })

    fireEvent.pointerDown(list, { pointerType: 'mouse', button: 0, clientX: 400 })
    fireEvent.pointerMove(list, { pointerType: 'mouse', clientX: 250 })
    fireEvent.pointerUp(list, { pointerType: 'mouse', clientX: 250 })

    expect(list.scrollLeft).toBe(150)
    expect(fireEvent.click(screen.getByRole('link', { name: /Film 3/ }))).toBe(false)
  })
})
