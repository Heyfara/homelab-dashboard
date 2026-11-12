import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { GridIcon, HomeIcon, LightbulbIcon, Printer3dIcon, ShieldIcon } from '../ui/icons'
import { TabBar, tabIcon } from './TabBar'

const tabs = [
  { slug: 'accueil', title: 'Accueil' },
  { slug: 'admin', title: 'Admin' },
]

describe('TabBar', () => {
  it('un lien par onglet : le premier à l’adresse du profil, l’actif marqué', () => {
    render(<TabBar profile="tom" tabs={tabs} active="admin" onChange={() => {}} />)

    const nav = screen.getByRole('navigation', { name: 'Onglets' })
    expect(screen.getByRole('link', { name: 'Accueil' }).getAttribute('href')).toBe('/tom')
    expect(screen.getByRole('link', { name: 'Admin' }).getAttribute('href')).toBe('/tom/admin')
    expect(screen.getByRole('link', { name: 'Admin' }).getAttribute('aria-current')).toBe('page')
    expect(screen.getByRole('link', { name: 'Accueil' }).getAttribute('aria-current')).toBeNull()
    // Onglet actif : bordure gauche (courbée par l'arrondi) et icône dans la couleur d'accent ; les autres, bordure transparente
    expect(screen.getByRole('link', { name: 'Admin' }).className).toContain('sm:border-indigo-500')
    expect(screen.getByRole('link', { name: 'Accueil' }).className).toContain('sm:border-transparent')
    expect(screen.getByRole('link', { name: 'Admin' }).querySelector('svg')!.getAttribute('class')).toContain('text-indigo-600')
    expect(screen.getByRole('link', { name: 'Accueil' }).querySelector('svg')!.getAttribute('class')).not.toContain('text-indigo-600')
    expect(nav.className).toContain('fixed') // barre en bas sur téléphone
    expect(nav.className).toContain('sm:sticky') // barre latérale sur ordinateur
    expect(screen.getByRole('link', { name: 'Admin' }).getAttribute('title')).toBe('Admin') // nom au survol en mode icônes
  })

  it('change d’onglet sans recharger la page', () => {
    const onChange = vi.fn()
    render(<TabBar profile="tom" tabs={tabs} active="accueil" onChange={onChange} />)

    const click = new MouseEvent('click', { bubbles: true, cancelable: true })
    fireEvent(screen.getByRole('link', { name: 'Admin' }), click)

    expect(onChange).toHaveBeenCalledWith('admin')
    expect(click.defaultPrevented).toBe(true)
  })

  it('laisse le navigateur gérer Ctrl+clic (nouvel onglet)', () => {
    const onChange = vi.fn()
    render(<TabBar profile="tom" tabs={tabs} active="accueil" onChange={onChange} />)

    fireEvent.click(screen.getByRole('link', { name: 'Admin' }), { ctrlKey: true })

    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('TabBar : mode compact', () => {
  it('le bouton du haut replie la barre en icônes seules, et s’en souvient', () => {
    const { unmount } = render(<TabBar profile="tom" tabs={tabs} active="accueil" onChange={() => {}} />)
    const nav = screen.getByRole('navigation', { name: 'Onglets' })
    expect(nav.className).toContain('lg:w-56')

    screen.getByText('Maison')
    screen.getByRole('separator')

    fireEvent.click(screen.getByRole('button', { name: 'Réduire la barre' }))

    expect(nav.className).not.toContain('lg:w-56')
    expect(screen.queryByText('Maison')).toBeNull()
    expect(screen.getByRole('button', { name: 'Déplier la barre' }).getAttribute('aria-expanded')).toBe('false')
    unmount()

    render(<TabBar profile="tom" tabs={tabs} active="accueil" onChange={() => {}} />)
    expect(screen.getByRole('navigation', { name: 'Onglets' }).className).not.toContain('lg:w-56')
    screen.getByRole('button', { name: 'Déplier la barre' })
  })
})

describe('tabIcon', () => {
  it('maison pour le premier onglet, icône connue pour les noms courants, grille sinon', () => {
    expect(tabIcon('accueil', true)).toBe(HomeIcon)
    expect(tabIcon('admin', false)).toBe(ShieldIcon)
    expect(tabIcon('atelier', false)).toBe(Printer3dIcon)
    expect(tabIcon('maison', false)).toBe(LightbulbIcon)
    expect(tabIcon('divers', false)).toBe(GridIcon)
  })
})
