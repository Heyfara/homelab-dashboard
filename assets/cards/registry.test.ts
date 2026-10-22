import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { cardRegistry } from './registry'

// La CI écrit la sortie de `bin/console dashboard:card-types` dans ce fichier.
const phpTypesFile = process.env.CARD_TYPES_FILE

describe('registre des cartes', () => {
  it.skipIf(!phpTypesFile)('contient exactement les types déclarés côté PHP', () => {
    const phpTypes = readFileSync(phpTypesFile!, 'utf8').split('\n').map((l) => l.trim()).filter(Boolean)

    expect(Object.keys(cardRegistry).sort()).toEqual(phpTypes.sort())
  })
})
