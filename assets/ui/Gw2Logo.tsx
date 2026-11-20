/** Icône officielle de Guild Wars 2, servie par le wiki (128 × 128, pastille ronde sur fond blanc). */
export const GW2_LOGO = 'https://wiki.guildwars2.com/images/7/7d/GW2_icon_small.png'

/** Icône du jeu dans le titre de la carte des boss, en 28 px. */
export function Gw2Logo() {
  return <img loading="lazy" src={GW2_LOGO} alt="" className="size-7 shrink-0" />
}
