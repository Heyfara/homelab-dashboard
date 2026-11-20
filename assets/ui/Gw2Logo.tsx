/** Icône officielle de Guild Wars 2, servie par le wiki (128 × 128, pastille ronde). */
export const GW2_LOGO = 'https://wiki.guildwars2.com/images/7/7d/GW2_icon_small.png'

/** Icône du jeu dans le titre des cartes GW2, à la taille des icônes des autres cartes. */
export function Gw2Logo() {
  return <img loading="lazy" src={GW2_LOGO} alt="" className="size-6 shrink-0" />
}
