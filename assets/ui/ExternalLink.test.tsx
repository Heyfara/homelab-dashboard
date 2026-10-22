import { render, screen } from '@testing-library/react'
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { ExternalLink } from './ExternalLink'

describe('ExternalLink', () => {
  it('ouvre dans un nouvel onglet, sans accès de la page ouverte au dashboard', () => {
    render(
      <ExternalLink href="https://immich.example" className="x">
        Immich
      </ExternalLink>,
    )

    const link = screen.getByRole('link', { name: 'Immich' })
    expect(link.getAttribute('target')).toBe('_blank')
    expect(link.getAttribute('rel')).toBe('noopener noreferrer')
    expect(link.getAttribute('href')).toBe('https://immich.example')
    expect(link.className).toBe('x')
  })

  it('aucune carte n’utilise de lien brut : tout lien de carte passe par ExternalLink', () => {
    const dir = join(import.meta.dirname, '../cards')
    const offenders = readdirSync(dir)
      .filter((f) => f.endsWith('.tsx') && !f.endsWith('.test.tsx'))
      .filter((f) => /<a[\s>]/.test(readFileSync(join(dir, f), 'utf8')))

    expect(offenders).toEqual([])
  })
})
