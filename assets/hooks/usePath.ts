import { useCallback, useEffect, useState } from 'react'

/**
 * Routage minimal : le chemin courant et une fonction pour en changer sans
 * recharger la page. Suffit pour les deux écrans (/ et /:profil).
 */
export function usePath(): [string, (path: string, options?: { replace?: boolean }) => void] {
  const [path, setPath] = useState(() => window.location.pathname)

  useEffect(() => {
    const onPopState = () => setPath(window.location.pathname)
    window.addEventListener('popstate', onPopState)
    return () => window.removeEventListener('popstate', onPopState)
  }, [])

  const navigate = useCallback((to: string, options: { replace?: boolean } = {}) => {
    if (options.replace) window.history.replaceState(null, '', to)
    else window.history.pushState(null, '', to)
    setPath(to)
  }, [])

  return [path, navigate]
}
