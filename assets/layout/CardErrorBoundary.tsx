import { Component, type ReactNode } from 'react'

type Props = { children: ReactNode; resetKey: unknown }

/**
 * Contient l'erreur d'une carte qui plante au rendu : le reste de la page
 * continue de s'afficher. Cas typique : des données gardées par le navigateur
 * (http/snapshots.ts) au format d'une ancienne version de la carte. La carte
 * réessaie dès que `resetKey` change (nouvelle réponse du serveur).
 */
export class CardErrorBoundary extends Component<Props, { failed: boolean; key: unknown }> {
  state = { failed: false, key: this.props.resetKey }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  static getDerivedStateFromProps(props: Props, state: { failed: boolean; key: unknown }) {
    return props.resetKey !== state.key ? { failed: false, key: props.resetKey } : null
  }

  render() {
    if (!this.state.failed) return this.props.children

    return (
      <p title="Si ça persiste après le prochain rafraîchissement : vider le stockage local (localStorage) du site dans le navigateur, puis recharger la page." className="rounded-lg bg-rose-50 px-2.5 py-1.5 text-xs text-rose-800 dark:bg-rose-950 dark:text-rose-300">
        Affichage impossible : les données gardées par le navigateur viennent peut-être d’une ancienne version. Si ça persiste, vider le stockage local du site (localStorage) puis recharger.
      </p>
    )
  }
}
