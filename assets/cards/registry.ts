import type { ReactNode } from 'react'
import type { CardResult, CardView } from '../http/types'
import { AudiobookshelfCard } from './AudiobookshelfCard'
import { BackblazeCard } from './BackblazeCard'
import { BackupsCard } from './BackupsCard'
import { BrickTrackerCard } from './BrickTrackerCard'
import { CalibreWebCard } from './CalibreWebCard'
import { DocFetcherCard } from './DocFetcherCard'
import { ImmichCard } from './ImmichCard'
import { C411Card } from './C411Card'
import { ContainersCard } from './ContainersCard'
import { GithubPullRequestsCard } from './GithubPullRequestsCard'
import { JellyfinLatestCard } from './JellyfinLatestCard'
import { MachineCard } from './MachineCard'
import { MealieCard } from './MealieCard'
import { QbittorrentCard } from './QbittorrentCard'
import { CraftyCard } from './CraftyCard'
import { Gw2Card } from './Gw2Card'
import { JeluCard } from './JeluCard'
import { MinecraftCard } from './MinecraftCard'
import { MusicAssistantCard } from './MusicAssistantCard'
import { WeatherCard } from './WeatherCard'
import { ServiceCard } from './ServiceCard'

/**
 * Composant d'un type de carte. P = props fixes (describe() côté PHP),
 * D = données vivantes (fetch() côté PHP). result vaut null avant la 1re réponse.
 */
export type CardComponent<P = any, D = any> = (props: {
  card: CardView<P>
  result: CardResult<D> | null
  /** Résultat de chaque flux de la carte (card.feeds), null avant sa 1re réponse */
  feeds?: Record<string, CardResult | null>
}) => ReactNode

/**
 * Un composant par type de carte, sous l'identifiant de son #[AsCard] PHP.
 * La CI vérifie que les deux listes sont identiques (voir registry.test.ts).
 */
export const cardRegistry: Record<string, CardComponent> = {
  service: ServiceCard,
  'jellyfin-latest': JellyfinLatestCard,
  qbittorrent: QbittorrentCard,
  c411: C411Card,
  machine: MachineCard,
  'github-prs': GithubPullRequestsCard,
  containers: ContainersCard,
  backblaze: BackblazeCard,
  mealie: MealieCard,
  audiobookshelf: AudiobookshelfCard,
  immich: ImmichCard,
  'calibre-web': CalibreWebCard,
  backups: BackupsCard,
  'doc-fetcher': DocFetcherCard,
  bricktracker: BrickTrackerCard,
  weather: WeatherCard,
  'music-assistant': MusicAssistantCard,
  jelu: JeluCard,
  crafty: CraftyCard,
  minecraft: MinecraftCard,
  gw2: Gw2Card,
}
