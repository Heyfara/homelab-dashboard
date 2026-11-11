import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// Sans `globals: true`, Testing Library ne démonte pas les rendus tout seul.
// Les dernières données gardées (http/snapshots) ne passent pas d'un test à l'autre.
afterEach(() => {
  cleanup()
  localStorage.clear()
})
