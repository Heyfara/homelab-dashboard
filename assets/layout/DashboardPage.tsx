import { useEffect, useRef, useState, type DragEvent } from 'react'
import { fetchDashboard, resetLayout, saveLayout } from '../http/client'
import type { CardView, DashboardView } from '../http/types'
import { useJson } from '../hooks/useJson'
import { profileBadge } from '../ui/profileColors'
import { CardSlot } from './CardSlot'
import { ErrorMessage } from './ErrorMessage'
import { moveBy, placeNextTo } from './reorder'
import { SectionGrid } from './SectionGrid'

/** Ordre en cours de modification : identifiants des cartes, section par section. */
type Draft = string[][]

/** Glisser en cours : section d'origine, carte tenue, ordre à rétablir si on lâche ailleurs. */
type Drag = { section: number; id: string; before: Draft; dropped: boolean; movedAt: number }

/** Après un déplacement, laisser la grille se recaler avant le suivant (évite le va-et-vient entre deux cartes de tailles différentes). */
const SETTLE_MS = 120

const button =
  'rounded-lg px-3 py-1.5 text-sm text-zinc-600 hover:bg-zinc-200 focus-visible:outline-2 focus-visible:outline-indigo-500 disabled:opacity-50 dark:text-slate-400 dark:hover:bg-slate-800'

const cardName = (card: CardView) => card.title ?? [card.props.title, card.props.name].find((v): v is string => typeof v === 'string') ?? card.type

export function DashboardPage({ slug, onChangeProfile, onOpenConfig }: { slug: string; onChangeProfile: () => void; onOpenConfig?: () => void }) {
  const { data, error } = useJson((signal) => fetchDashboard(slug, signal), slug)
  // Copie locale : remplacée après « Enregistrer » ou « Ordre par défaut », sans recharger les cartes.
  const [dashboard, setDashboard] = useState<DashboardView | null>(null)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [busy, setBusy] = useState(false)
  const [saveError, setSaveError] = useState<Error | null>(null)
  const drag = useRef<Drag | null>(null)
  const [dragging, setDragging] = useState<string | null>(null)

  useEffect(() => {
    setDashboard(data)
    setDraft(null)
  }, [data])

  useEffect(() => {
    document.title = dashboard ? `Maison · ${dashboard.profile.name}` : 'Maison'
  }, [dashboard])

  const editing = draft !== null

  const startEditing = () => {
    if (!dashboard) return
    setSaveError(null)
    setDraft(dashboard.sections.map((s) => s.cards.map((c) => c.id)))
  }

  const updateSection = (section: number, change: (ids: string[]) => string[]) =>
    setDraft((d) => {
      if (!d) return d
      const next = change(d[section]!)
      return next === d[section] ? d : d.map((ids, i) => (i === section ? next : ids))
    })

  const save = async () => {
    if (!dashboard || !draft) return
    setBusy(true)
    setSaveError(null)
    try {
      await saveLayout(slug, draft.flat())
      setDashboard(arrange(dashboard, draft, true))
      setDraft(null)
    } catch (e) {
      setSaveError(e instanceof Error ? e : new Error(String(e)))
    } finally {
      setBusy(false)
    }
  }

  const reset = async () => {
    setBusy(true)
    setSaveError(null)
    try {
      await resetLayout(slug)
      setDashboard(await fetchDashboard(slug))
      setDraft(null)
    } catch (e) {
      setSaveError(e instanceof Error ? e : new Error(String(e)))
    } finally {
      setBusy(false)
    }
  }

  const onDragStart = (section: number, id: string) => (event: DragEvent) => {
    if (!draft) return
    event.dataTransfer?.setData('text/plain', id) // Firefox ne démarre pas le glisser sans données
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
    drag.current = { section, id, before: draft, dropped: false, movedAt: 0 }
    setDragging(id)
  }

  // Pendant le survol, la carte tenue prend place avant ou après la carte survolée
  // (selon la moitié survolée) : la grille se recompose en direct.
  const onDragOver = (section: number, target: string) => (event: DragEvent<HTMLElement>) => {
    const current = drag.current
    if (!current || current.section !== section) return
    event.preventDefault()
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
    if (target === current.id || Date.now() - current.movedAt < SETTLE_MS) return
    const rect = event.currentTarget.getBoundingClientRect()
    const side = event.clientX - rect.left < rect.width / 2 ? 'before' : 'after'
    updateSection(section, (ids) => {
      const next = placeNextTo(ids, current.id, target, side)
      if (next !== ids) current.movedAt = Date.now()
      return next
    })
  }

  const onDrop = (event: DragEvent) => {
    if (!drag.current) return
    event.preventDefault()
    drag.current.dropped = true
  }

  // Lâchée hors d'une carte de sa section, ou Échap : la carte revient à sa place.
  const onDragEnd = () => {
    const current = drag.current
    if (current && !current.dropped) setDraft(current.before)
    drag.current = null
    setDragging(null)
  }

  const sections = dashboard ? (draft ? arrange(dashboard, draft, dashboard.customized).sections : dashboard.sections) : []

  return (
    <main className="mx-auto flex max-w-7xl flex-col gap-8 px-4 py-6 sm:px-6">
      <header className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          {dashboard && (
            <span className={`grid size-9 place-items-center rounded-full font-semibold text-white ${profileBadge[dashboard.profile.color]}`} aria-hidden="true">
              {dashboard.profile.name.charAt(0).toUpperCase()}
            </span>
          )}
          <h1 className="text-xl font-semibold text-zinc-900 dark:text-slate-50">{dashboard?.profile.name ?? ' '}</h1>
        </div>
        {editing ? (
          <div className="flex flex-wrap items-center gap-1">
            <span className="mr-2 hidden text-sm text-zinc-500 md:inline dark:text-slate-400">Glisser les cartes, ou utiliser les flèches.</span>
            {dashboard?.customized && (
              <button type="button" onClick={reset} disabled={busy} className={button}>
                Ordre par défaut
              </button>
            )}
            <button type="button" onClick={() => setDraft(null)} disabled={busy} className={button}>
              Annuler
            </button>
            <button
              type="button"
              onClick={save}
              disabled={busy}
              className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500 disabled:opacity-50"
            >
              Enregistrer
            </button>
          </div>
        ) : (
          <div className="flex items-center gap-1">
            {dashboard && (
              <button type="button" onClick={startEditing} className={button}>
                Réorganiser
              </button>
            )}
            {onOpenConfig && (
              <button type="button" onClick={onOpenConfig} className={button}>
                Ma config
              </button>
            )}
            <button type="button" onClick={onChangeProfile} className={button}>
              Changer de profil
            </button>
          </div>
        )}
      </header>

      {error && <ErrorMessage error={error} />}
      {saveError && <ErrorMessage error={saveError} />}

      {sections.map((section, sectionIndex) => (
        <section key={section.title} aria-labelledby={`section-${sectionIndex}`} className="flex flex-col gap-3">
          <h2 id={`section-${sectionIndex}`} className="text-sm font-semibold tracking-wide text-zinc-500 uppercase dark:text-slate-400">
            {section.title}
          </h2>
          <SectionGrid
            columns={section.columns}
            items={section.cards.map((card) => ({ ...card, key: card.id }))}
            render={(card) => {
              const position = section.cards.findIndex((c) => c.id === card.id)
              return (
                <div
                  data-testid={`card-${card.id}`}
                  draggable={editing}
                  onDragStart={onDragStart(sectionIndex, card.id)}
                  onDragOver={onDragOver(sectionIndex, card.id)}
                  onDrop={onDrop}
                  onDragEnd={onDragEnd}
                  className={`relative h-full ${dragging === card.id ? 'opacity-40' : ''}`}
                >
                  <CardSlot card={card} />
                  {editing && (
                    <ReorderOverlay
                      name={cardName(card)}
                      first={position === 0}
                      last={position === section.cards.length - 1}
                      onMove={(delta) => updateSection(sectionIndex, (ids) => moveBy(ids, card.id, delta))}
                    />
                  )}
                </div>
              )
            }}
          />
        </section>
      ))}
    </main>
  )
}

/**
 * Voile posé sur une carte en mode « Réorganiser » : il bloque les liens de la
 * carte, sert de poignée pour glisser, et porte les flèches (clavier, tactile).
 */
function ReorderOverlay({ name, first, last, onMove }: { name: string; first: boolean; last: boolean; onMove: (delta: -1 | 1) => void }) {
  const arrow =
    'grid size-9 place-items-center rounded-full bg-white text-lg text-zinc-700 shadow ring-1 ring-zinc-200 hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-indigo-500 disabled:opacity-30 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-700'

  return (
    <div className="absolute inset-0 z-10 flex cursor-grab flex-col items-center justify-center gap-2 rounded-2xl bg-white/60 ring-2 ring-indigo-400/70 backdrop-blur-[2px] active:cursor-grabbing dark:bg-slate-950/60 dark:ring-indigo-500/60">
      <span className="max-w-[90%] truncate rounded-md px-2 text-sm font-semibold text-zinc-900 dark:text-slate-50">{name}</span>
      <div className="flex gap-2">
        <button type="button" aria-label={`Déplacer « ${name} » vers la gauche`} disabled={first} onClick={() => onMove(-1)} className={arrow}>
          ←
        </button>
        <button type="button" aria-label={`Déplacer « ${name} » vers la droite`} disabled={last} onClick={() => onMove(1)} className={arrow}>
          →
        </button>
      </div>
    </div>
  )
}

/** Le dashboard avec ses cartes dans l'ordre du brouillon. */
function arrange(dashboard: DashboardView, draft: Draft, customized: boolean): DashboardView {
  return {
    ...dashboard,
    customized,
    sections: dashboard.sections.map((section, i) => {
      const byId = new Map(section.cards.map((c) => [c.id, c]))
      return { ...section, cards: (draft[i] ?? []).map((id) => byId.get(id)).filter((c): c is CardView => c !== undefined) }
    }),
  }
}
