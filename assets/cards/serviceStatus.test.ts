import { describe, expect, it } from 'vitest'
import { serviceStatus, type ServiceData } from './serviceStatus'

const running = { status: 'running', health: null, uptime: 'Up 7 days', cpu: 1.5, memory: 200 } as const
const up = { up: true, statusCode: 200, latencyMs: 12, error: null }
const down = { up: false, statusCode: null, latencyMs: 3000, error: 'timeout' }
const data = (d: Partial<ServiceData>): ServiceData => ({ container: null, probe: null, ...d })

describe('serviceStatus', () => {
  it.each([
    ['en ligne', data({ container: running, probe: up }), 'ok', 'En ligne'],
    ['sonde seule', data({ probe: up }), 'ok', 'En ligne'],
    ['container seul', data({ container: running }), 'ok', 'En ligne'],
    ['healthy', data({ container: { ...running, health: 'healthy' }, probe: up }), 'ok', 'En ligne'],
    ['démarrage', data({ container: { ...running, health: 'starting' } }), 'warn', 'Démarre'],
    ['redémarre en boucle', data({ container: { ...running, status: 'restarting' } }), 'warn', 'Redémarre'],
    ['unhealthy', data({ container: { ...running, health: 'unhealthy' }, probe: up }), 'down', 'En difficulté'],
    ['en pause', data({ container: { ...running, status: 'paused' } }), 'warn', 'En pause'],
    ['arrêté', data({ container: { status: 'stopped', health: null, uptime: null, cpu: null, memory: null } }), 'down', 'Arrêté'],
    ['ne répond pas', data({ container: running, probe: down }), 'down', 'Ne répond pas'],
    ['Beszel injoignable mais service OK', data({ container: { error: 'Beszel injoignable' }, probe: up }), 'ok', 'En ligne'],
    ['rien à surveiller', data({}), 'unknown', 'Non surveillé'],
  ] as const)('%s', (_, input, tone, label) => {
    expect(serviceStatus(input)).toEqual({ tone, label })
  })

  it('sans données', () => {
    expect(serviceStatus(null)).toEqual({ tone: 'unknown', label: 'Vérification…' })
  })
})
