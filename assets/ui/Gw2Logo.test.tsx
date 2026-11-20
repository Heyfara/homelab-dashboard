import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { GW2_LOGO, Gw2Logo } from './Gw2Logo'

describe('Gw2Logo', () => {
  it('l’icône ronde du jeu (wiki), en 28 px', () => {
    const { container } = render(<Gw2Logo />)

    const img = container.querySelector('img')!
    expect(img.getAttribute('src')).toBe(GW2_LOGO)
    expect(GW2_LOGO).toBe('https://wiki.guildwars2.com/images/7/7d/GW2_icon_small.png')
    expect(img.getAttribute('alt')).toBe('')
    expect(img.className).toContain('size-7')
  })
})
