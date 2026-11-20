import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { GW2_LOGO, Gw2Logo } from './Gw2Logo'

describe('Gw2Logo', () => {
  it('le logo transparent du jeu (wiki), à la hauteur des icônes des autres cartes', () => {
    const { container } = render(<Gw2Logo />)

    const img = container.querySelector('img')!
    expect(img.getAttribute('src')).toBe(GW2_LOGO)
    expect(GW2_LOGO).toBe('https://wiki.guildwars2.com/images/thumb/d/df/GW2Logo_new.png/160px-GW2Logo_new.png')
    expect(img.getAttribute('alt')).toBe('')
    expect(img.className).toContain('h-10')
    expect(img.className).toContain('-my-1.5')
  })
})
