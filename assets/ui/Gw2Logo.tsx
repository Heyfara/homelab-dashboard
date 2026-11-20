/** Logo officiel de Guild Wars 2, fond transparent, servi par le wiki (miniature 160 × 111). */
export const GW2_LOGO = 'https://wiki.guildwars2.com/images/thumb/d/df/GW2Logo_new.png/160px-GW2Logo_new.png'

/** Logo du jeu dans le titre des cartes GW2, à la hauteur des icônes des autres cartes (ServiceIcon : 40 px), sans agrandir le titre : marges négatives, il n'occupe que 28 px de haut (la carte des boss tient sur une rangée). */
export function Gw2Logo() {
  return <img loading="lazy" src={GW2_LOGO} alt="" className="-my-1.5 h-10 w-auto shrink-0" />
}
