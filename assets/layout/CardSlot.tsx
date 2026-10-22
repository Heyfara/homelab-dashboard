import { useCallback, useEffect, useState } from 'react'
import { fetchCard } from '../http/client'
import type { CardResult, CardView } from '../http/types'
import { cardRegistry, type CardComponent } from '../cards/registry'
import { useCardData } from '../hooks/useCardData'
import { CardFrame } from './CardFrame'

type Fetcher = (id: string, signal: AbortSignal, feed?: string) => Promise<CardResult>

type Props = {
  card: CardView
  registry?: Record<string, CardComponent>
  fetcher?: Fetcher
}

/** Choisit le composant du type de la carte et lui fournit ses données vivantes. */
export function CardSlot({ card, registry = cardRegistry, fetcher = fetchCard }: Props) {
  const Component = registry[card.type]
  if (!Component) {
    return <CardFrame error={`Type de carte sans composant React : « ${card.type} » (voir assets/cards/registry.ts)`}>{null}</CardFrame>
  }

  return <LiveCard card={card} Component={Component} fetcher={fetcher} />
}

function LiveCard({ card, Component, fetcher }: { card: CardView; Component: CardComponent; fetcher: Fetcher }) {
  const { result, error } = useCardData(card.id, card.refresh, fetcher)
  const [feeds, setFeeds] = useState<Record<string, CardResult | null>>({})
  const onFeed = useCallback((feed: string, value: CardResult | null) => setFeeds((f) => (f[feed] === value ? f : { ...f, [feed]: value })), [])
  const message = result?.status === 'error' ? result.error : error ? `Pas de réponse du serveur (${error})` : null

  return (
    <CardFrame error={message}>
      {Object.entries(card.feeds ?? {}).map(([feed, refresh]) => (
        <FeedPoller key={feed} id={card.id} feed={feed} refresh={refresh} fetcher={fetcher} onResult={onFeed} />
      ))}
      <Component card={card} result={result} feeds={feeds} />
    </CardFrame>
  )
}

/** Un flux de la carte, interrogé à son propre rythme ; ne rend rien. */
function FeedPoller({ id, feed, refresh, fetcher, onResult }: { id: string; feed: string; refresh: number; fetcher: Fetcher; onResult: (feed: string, value: CardResult | null) => void }) {
  const feedFetcher = useCallback((cardId: string, signal: AbortSignal) => fetcher(cardId, signal, feed), [fetcher, feed])
  const { result } = useCardData(id, refresh, feedFetcher)

  useEffect(() => onResult(feed, result), [feed, result, onResult])

  return null
}
