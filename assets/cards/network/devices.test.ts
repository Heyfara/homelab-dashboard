import { describe, expect, it } from 'vitest'
import { countDevices, deviceLabel, filterDevices, ipMismatch, isUnknown, type Device } from './devices'

const device = (d: Partial<Device>): Device => ({
  mac: '00:16:3E:00:00:01',
  name: 'portable',
  vendor: 'Fabricant',
  type: 'laptop',
  ip: '192.168.1.10',
  expectedIp: null,
  online: true,
  lastSeen: '2026-11-24T11:59:00+00:00',
  randomMac: false,
  ...d,
})

describe('appareils du réseau', () => {
  it('nom affiché : nom, sinon fabricant, sinon adresse privée', () => {
    expect(deviceLabel(device({}))).toBe('portable')
    expect(deviceLabel(device({ name: null }))).toBe('Appareil Fabricant')
    expect(deviceLabel(device({ name: null, vendor: null, randomMac: true }))).toBe('Adresse privée')
    expect(deviceLabel(device({ name: null, vendor: null }))).toBe('Appareil sans nom')
  })

  it('inconnu = sans nom', () => {
    expect(isUnknown(device({}))).toBe(false)
    expect(isUnknown(device({ name: null }))).toBe(true)
  })

  it('IP différente du bail statique', () => {
    expect(ipMismatch(device({ expectedIp: '192.168.1.10' }))).toBe(false)
    expect(ipMismatch(device({ expectedIp: '192.168.1.11' }))).toBe(true)
    expect(ipMismatch(device({ expectedIp: null }))).toBe(false)
    expect(ipMismatch(device({ ip: null, expectedIp: '192.168.1.11' }))).toBe(false)
  })

  it('filtres et compteurs', () => {
    const list = [device({}), device({ mac: 'b', online: false }), device({ mac: 'c', name: null })]

    expect(filterDevices(list, 'all')).toHaveLength(3)
    expect(filterDevices(list, 'online').map((d) => d.mac)).toEqual(['00:16:3E:00:00:01', 'c'])
    expect(filterDevices(list, 'unknown').map((d) => d.mac)).toEqual(['c'])
    expect(countDevices(list)).toEqual({ all: 3, online: 2, unknown: 1 })
  })
})
