import { describe, expect, it } from 'vitest'
import type { NetworkData } from '../NetworkCard'
import type { Device, Place } from './devices'
import { buildZones, flowSpeed, layout, pathOf, related } from './layout'

const place = (p: Partial<Place>): Place => ({ zone: 'Bureau', ap: 'flint', link: 'wifi', port: 'rax0', band: '5', signal: -40, seenAt: null, ...p })
const baie: Place = { zone: 'Baie', ap: null, link: 'cable', port: null, band: null, signal: null, seenAt: null }

const device = (mac: string, name: string, d: Partial<Device> = {}): Device => ({
  mac,
  name,
  vendor: null,
  type: 'other',
  ip: `192.168.1.${Number.parseInt(mac.slice(-2), 16)}`,
  expectedIp: null,
  online: true,
  lastSeen: null,
  randomMac: false,
  place: baie,
  firstSeen: null,
  changes: 0,
  ...d,
})

const data: NetworkData = {
  devices: [
    device('00:00:00:00:00:01', 'serveur', { type: 'server' }),
    device('00:00:00:00:00:02', 'flint', { type: 'network' }),
    device('00:00:00:00:00:03', 'pc', { type: 'computer', place: place({ link: 'cable', port: 'lan2', band: null, signal: null }) }),
    device('00:00:00:00:00:04', 'tablette', { type: 'tablet', place: place({}) }),
    device('00:00:00:00:00:05', 'éteint', { online: false, place: place({}) }),
    device('00:00:00:00:00:06', 'perdu', { place: null }),
  ],
  accessPoints: [{ id: 'flint', zone: 'Bureau', error: null, uplink: 'eth1', radios: { ra0: '2.4', rax0: '5' }, device: '00:00:00:00:00:02', kind: 'openwrt' }],
  wiredZone: 'Baie',
}

describe('zones du schéma', () => {
  it('la baie, puis un point d’accès mené par son propre appareil, puis l’incertaine ; hors ligne absents', () => {
    const zones = buildZones(data)

    expect(zones.map((z) => [z.key, z.label, z.tone])).toEqual([
      ['wired', 'Baie', 'wired'],
      ['ap:flint', 'Bureau', 'ap1'],
      ['uncertain', 'Position incertaine', 'uncertain'],
    ])
    expect(zones[0]!.devices.map((d) => d.name)).toEqual(['serveur'])
    expect(zones[1]!.head?.name).toBe('flint')
    expect(zones[1]!.devices.map((d) => d.name)).toEqual(['pc', 'tablette'])
    expect(zones[2]!.devices.map((d) => d.name)).toEqual(['perdu'])
  })

  it('pas de zone incertaine vide', () => {
    expect(buildZones({ ...data, devices: data.devices.filter((d) => d.place !== null) }).map((z) => z.key)).not.toContain('uncertain')
  })
})

describe('placement du schéma', () => {
  it('large : une colonne par zone, côte à côte', () => {
    const d = layout(buildZones(data), 1200)
    const boxes = d.zones.map((z) => [z.x, z.y])

    expect(new Set(boxes.map(([x]) => x)).size).toBe(3)
    expect(new Set(boxes.map(([, y]) => y)).size).toBe(1)
    expect(d.width).toBe(1200)
  })

  it('étroit : les zones s’empilent', () => {
    const d = layout(buildZones(data), 360)

    expect(d.zones.every((z) => z.x === 0 && z.w === 360)).toBe(true)
    expect(d.zones[1]!.y).toBeGreaterThan(d.zones[0]!.y + d.zones[0]!.h)
    expect(d.height).toBeGreaterThan(d.zones[2]!.y + d.zones[2]!.h)
  })

  it('liens : débit Internet en tête, amont du point d’accès, port câblé ; le Wi-Fi n’est pas mesuré', () => {
    const d = layout(buildZones(data), 1200)
    const link = (to: string) => d.links.find((l) => l.to === to)!

    expect(link('box')).toMatchObject({ from: 'internet', kind: 'cable', rate: { kind: 'wan' } })
    expect(link('00:00:00:00:00:02')).toMatchObject({ from: 'switch', rate: { kind: 'port', ap: 'flint', port: 'eth1' } })
    expect(link('00:00:00:00:00:03')).toMatchObject({ from: '00:00:00:00:00:02', kind: 'cable', rate: { kind: 'port', ap: 'flint', port: 'lan2' } })
    expect(link('00:00:00:00:00:04')).toMatchObject({ from: '00:00:00:00:00:02', kind: 'wifi', rate: null })
    expect(link('00:00:00:00:00:01')).toMatchObject({ from: 'switch', kind: 'cable', rate: { kind: 'host', ip: '192.168.1.1' } })
    expect(d.links.find((l) => l.to === '00:00:00:00:00:06')).toBeUndefined()
  })

  it('survol : le chemin jusqu’à Internet et ce qui est derrière', () => {
    const d = layout(buildZones(data), 1200)

    expect([...related(d, '00:00:00:00:00:04')].sort()).toEqual(['00:00:00:00:00:02', '00:00:00:00:00:04', 'box', 'internet', 'switch'])
    expect(related(d, '00:00:00:00:00:02')).toContain('00:00:00:00:00:03')
    expect(related(d, '00:00:00:00:00:02')).not.toContain('00:00:00:00:00:01')
  })
})

describe('vitesse des points animés', () => {
  it('rien sous 1 ko/s, puis lent, moyen, rapide', () => {
    expect(flowSpeed(undefined)).toBeNull()
    expect(flowSpeed(500)).toBeNull()
    expect(flowSpeed(20_000)).toBe('slow')
    expect(flowSpeed(1_000_000)).toBe('mid')
    expect(flowSpeed(20_000_000)).toBe('fast')
  })
})

describe('chemin d’un nœud', () => {
  it('d’Internet jusqu’à lui, par son point d’accès ; vide si sa position est incertaine', () => {
    expect(pathOf(data, 'switch')).toEqual(['Internet', 'Freebox', 'Switch'])
    expect(pathOf(data, '00:00:00:00:00:01')).toEqual(['Internet', 'Freebox', 'Switch', 'serveur'])
    expect(pathOf(data, '00:00:00:00:00:02')).toEqual(['Internet', 'Freebox', 'Switch', 'flint'])
    expect(pathOf(data, '00:00:00:00:00:04')).toEqual(['Internet', 'Freebox', 'Switch', 'flint', 'tablette'])
    expect(pathOf(data, '00:00:00:00:00:06')).toEqual([])
  })
})
