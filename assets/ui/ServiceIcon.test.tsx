import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { iconUrl, ServiceIcon } from './ServiceIcon'

describe('iconUrl', () => {
  it.each([
    // WebP par défaut : c'est le seul format que selfh.st fournit pour toutes les icônes.
    ['sh-immich', 'https://cdn.jsdelivr.net/gh/selfhst/icons/webp/immich.webp'],
    ['sh-immich.svg', 'https://cdn.jsdelivr.net/gh/selfhst/icons/svg/immich.svg'],
    ['sh-immich.png', 'https://cdn.jsdelivr.net/gh/selfhst/icons/png/immich.png'],
    ['sh-immich-dark.svg', 'https://cdn.jsdelivr.net/gh/selfhst/icons/svg/immich-dark.svg'],
    ['https://exemple.org/logo.png', 'https://exemple.org/logo.png'],
    ['immich', null],
    [null, null],
  ])('%s', (icon, url) => expect(iconUrl(icon)).toBe(url))
})

describe('ServiceIcon', () => {
  it('affiche l’initiale sans icône', () => {
    render(<ServiceIcon icon={null} name="photos" />)

    screen.getByText('P')
  })

  it('revient à l’initiale si l’image ne charge pas (nom d’icône inconnu)', () => {
    const { container } = render(<ServiceIcon icon="sh-php" name="API" />)

    fireEvent.error(container.querySelector('img')!)

    expect(container.querySelector('img')).toBeNull()
    screen.getByText('A')
  })
})
