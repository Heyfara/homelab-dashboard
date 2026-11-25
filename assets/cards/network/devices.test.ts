import { describe, expect, it } from 'vitest'
import { countDevices, countZones, deviceLabel, filterDevices, ipMismatch, isUnknown, placeLabel, UNCERTAIN, weakSignal, zoneOf, type Device, type Place } from './devices'

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
  place: null,
  ...d,
})

const place = (p: Partial<Place>): Place => ({ zone: 'Bureau', ap: 'flint', link: 'wifi', port: 'rax0', band: '5', signal: -40, seenAt: null, ...p })

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

  it('position : zone, puis Wi-Fi et bande, ou câble et port', () => {
    expect(placeLabel(place({}))).toBe('Bureau · Wi-Fi 5 GHz')
    expect(placeLabel(place({ band: '2.4' }))).toBe('Bureau · Wi-Fi 2,4 GHz')
    expect(placeLabel(place({ link: 'cable', port: 'lan2', band: null, signal: null }))).toBe('Bureau · câble lan2')
    expect(placeLabel(place({ zone: 'Baie', ap: null, link: 'cable', port: null, band: null, signal: null }))).toBe('Baie · câble')
  })

  it('signal faible sous -75 dBm', () => {
    expect(weakSignal(place({ signal: -76 }))).toBe(true)
    expect(weakSignal(place({ signal: -60 }))).toBe(false)
    expect(weakSignal(place({ signal: null }))).toBe(false)
  })

  it('zones : celle de la position, incertaine si en ligne sans position, rien sinon', () => {
    expect(zoneOf(device({ place: place({}) }))).toBe('Bureau')
    expect(zoneOf(device({ place: null }))).toBe(UNCERTAIN)
    expect(zoneOf(device({ place: null, online: false }))).toBeNull()
  })

  it('compte par zone, dans l’ordre donné, incertaine à la fin', () => {
    const devices = [device({ place: place({}) }), device({ place: place({ zone: 'Baie' }) }), device({ place: place({}) }), device({})]

    expect(countZones(devices, ['Bureau', 'Baie', 'Étage'])).toEqual([
      { zone: 'Bureau', count: 2 },
      { zone: 'Baie', count: 1 },
      { zone: UNCERTAIN, count: 1 },
    ])
    expect(filterDevices(devices, 'all', 'Baie')).toHaveLength(1)
    expect(filterDevices(devices, 'online', UNCERTAIN)).toHaveLength(1)
  })
})
