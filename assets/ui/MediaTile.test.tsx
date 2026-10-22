import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { MediaTile } from './MediaTile'

describe('MediaTile', () => {
  it('image recadrée par défaut', () => {
    render(<MediaTile href="https://x.example" image="/a.jpg" title="Titre" subtitle="Sous-titre" />)

    expect(screen.getByRole('link', { name: 'Titre (Sous-titre)' }).querySelector('img')?.className).toContain('object-cover')
  })

  it('« contain » : image entière sur fond blanc (photos de produits détourées)', () => {
    render(<MediaTile href="https://x.example" image="/a.jpg" title="Titre" fit="contain" />)

    const link = screen.getByRole('link', { name: 'Titre' })
    expect(link.querySelector('img')?.className).toContain('object-contain')
    expect(link.className).toContain('bg-white')
  })
})
