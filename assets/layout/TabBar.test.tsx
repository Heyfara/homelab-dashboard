import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { TabBar } from './TabBar'

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
    expect(nav.className).toContain('fixed') // barre en bas sur téléphone
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
