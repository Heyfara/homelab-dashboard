/** Logo officiel de Guild Wars 2, servi par le wiki (rendu PNG du SVG, 400 × 280). */
export const GW2_LOGO = 'https://wiki.guildwars2.com/images/thumb/d/db/Guild_Wars_2_logo.svg/400px-Guild_Wars_2_logo.svg.png'

/**
 * Icône du jeu pour les titres des cartes GW2 : la tête du dragon rouge du
 * logo, recadrée en CSS (zone 222 × 140 à partir de x = 95), le bas fondu
 * pour ne pas couper net le cou ni montrer le texte « GUILDWARS », illisible
 * à cette taille. Image du wiki, rien dans le dépôt.
 */
export function Gw2Logo() {
  return (
    <span aria-hidden="true" className="relative block h-6 w-[38px] shrink-0 overflow-hidden [mask-image:linear-gradient(to_bottom,black_60%,transparent_88%)]">
      {/* 24 px de haut pour 140 px du logo : échelle 0,171 */}
      <img loading="lazy" src={GW2_LOGO} alt="" className="absolute top-0 max-w-none" style={{ width: 68.5, left: -16.3 }} />
    </span>
  )
}
