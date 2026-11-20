import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { GW2_LOGO, Gw2Logo } from './Gw2Logo'

describe('Gw2Logo', () => {
  it('le dragon du logo officiel (wiki), recadré, décoratif', () => {
    const { container } = render(<Gw2Logo />)

    const img = container.querySelector('img')!
    expect(img.getAttribute('src')).toBe(GW2_LOGO)
    expect(GW2_LOGO).toMatch(/^https:\/\/wiki\.guildwars2\.com\/images\/.*Guild_Wars_2_logo\.svg\.png$/)
    expect(img.getAttribute('alt')).toBe('')
    expect(container.firstElementChild!.className).toContain('overflow-hidden')
  })
})
