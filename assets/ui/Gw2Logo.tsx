/** Logo officiel de Guild Wars 2, servi par le wiki (rendu PNG du SVG, 400 × 280). */
export const GW2_LOGO = 'https://wiki.guildwars2.com/images/thumb/d/db/Guild_Wars_2_logo.svg/400px-Guild_Wars_2_logo.svg.png'

/**
 * Icône du jeu pour les titres des cartes GW2 : la tête du dragon rouge du
 * logo, recadrée en CSS (zone 218 × 124 à partir de x = 92), sans le texte
 * « GUILDWARS » illisible à cette taille. Image du wiki, rien dans le dépôt.
 */
export function Gw2Logo() {
  return (
    <span aria-hidden="true" className="relative block h-7 w-12 shrink-0 overflow-hidden">
      {/* 28 px de haut pour 124 px du logo : échelle 0,226 */}
      <img loading="lazy" src={GW2_LOGO} alt="" className="absolute top-0 max-w-none" style={{ width: 90, left: -21 }} />
    </span>
  )
}
