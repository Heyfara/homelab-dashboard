import { useEffect } from 'react'
import { usePath } from './hooks/usePath'
import { CredentialsPage } from './layout/CredentialsPage'
import { DashboardPage } from './layout/DashboardPage'
import { ProfilePicker } from './layout/ProfilePicker'

const STORAGE_KEY = 'dashboard.profile'

// Le stockage peut être indisponible (navigation privée) : on s'en passe.
const remembered = {
  get: () => {
    try {
      return localStorage.getItem(STORAGE_KEY)
    } catch {
      return null
    }
  },
  set: (slug: string | null) => {
    try {
      if (slug) localStorage.setItem(STORAGE_KEY, slug)
      else localStorage.removeItem(STORAGE_KEY)
    } catch {
      /* rien */
    }
  },
}

/** Trois écrans : / (choix du profil), /:profil (son dashboard), /:profil/cles (ses clés d'API). */
export function App() {
  const [path, navigate] = usePath()
  const [slug = null, page = null] = path.split('/').filter(Boolean)

  useEffect(() => {
    const last = remembered.get()
    if (!slug && last) navigate(`/${last}`, { replace: true })
  }, [slug, navigate])

  if (!slug) {
    return (
      <ProfilePicker
        onPick={(picked) => {
          remembered.set(picked)
          navigate(`/${picked}`)
        }}
      />
    )
  }

  if (page === 'cles') {
    return <CredentialsPage slug={slug} onBack={() => navigate(`/${slug}`)} />
  }

  return (
    <DashboardPage
      slug={slug}
      onChangeProfile={() => {
        remembered.set(null)
        navigate('/')
      }}
      onOpenCredentials={() => navigate(`/${slug}/cles`)}
    />
  )
}
