import { readdirSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

// En dev, Vite relaie /api vers Symfony (vite.config.ts) : un fichier du front
// servi sous /api/… partirait vers l'API au lieu d'être compilé (page blanche,
// « type MIME interdit (application/json) »).
describe('proxy /api du serveur Vite', () => {
  it('aucun fichier du front n’est servi sous /api', () => {
    expect(readdirSync(import.meta.dirname)).not.toContain('api')
  })
})
