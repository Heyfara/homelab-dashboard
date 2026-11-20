/** Icône officielle de Guild Wars 2, servie par le wiki (128 × 128, pastille ronde sur fond blanc). */
export const GW2_LOGO = 'https://wiki.guildwars2.com/images/7/7d/GW2_icon_small.png'

/**
 * Icône du jeu dans le titre des cartes GW2, à la taille des icônes des autres
 * cartes (ServiceIcon : 40 px), sans agrandir le titre : marges négatives, elle
 * n'occupe que 28 px de haut (la carte des boss tient sur une rangée).
 */
export function Gw2Logo() {
  return <img loading="lazy" src={GW2_LOGO} alt="" className="-my-1.5 size-10 shrink-0" />
}
