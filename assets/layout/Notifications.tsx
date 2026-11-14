import { useCallback, useEffect, useRef, useState } from 'react'
import { fetchNotifications, markNotificationsRead } from '../http/client'
import type { NotificationView, NotificationsView } from '../http/types'
import { ExternalLink } from '../ui/ExternalLink'
import { formatRelative } from '../ui/format'
import { BellIcon, CloseIcon } from '../ui/icons'

/** Les messages sont relus toutes les 30 s (et au retour sur l'onglet). */
const POLL_MS = 30_000

/** Étiquettes ntfy affichées en émoji devant le titre, comme dans l'appli ntfy (les autres sont des mots-clés). */
const EMOJIS: Record<string, string> = {
  rotating_light: '🚨',
  warning: '⚠️',
  x: '❌',
  red_circle: '🔴',
  green_circle: '🟢',
  white_check_mark: '✅',
  heavy_check_mark: '✔️',
  battery: '🔋',
  wrench: '🔧',
  fire: '🔥',
  house: '🏠',
  bell: '🔔',
  floppy_disk: '💾',
  package: '📦',
  rocket: '🚀',
  tada: '🎉',
  skull: '💀',
  information_source: 'ℹ️',
}

export type NotificationsState = {
  data: NotificationsView | null
  error: Error | null
  markRead: (ids: string[]) => void
}

/**
 * Messages ntfy du profil, relus régulièrement ; `markRead` met à jour tout de
 * suite, puis le serveur. `enabled` faux : rien n'est chargé (profil sans sujets).
 */
export function useNotifications(slug: string, enabled = true): NotificationsState {
  const [data, setData] = useState<NotificationsView | null>(null)
  const [error, setError] = useState<Error | null>(null)
  const controller = useRef<AbortController | null>(null)

  const load = useCallback(() => {
    controller.current?.abort()
    const current = new AbortController()
    controller.current = current
    fetchNotifications(slug, current.signal).then(
      (view) => {
        setData(view)
        setError(null)
      },
      (e: unknown) => {
        if (!current.signal.aborted) setError(e instanceof Error ? e : new Error(String(e)))
      },
    )
  }, [slug])

  useEffect(() => {
    if (!enabled) return
    load()
    const timer = setInterval(() => document.visibilityState !== 'hidden' && load(), POLL_MS)
    const onVisible = () => document.visibilityState === 'visible' && load()
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      clearInterval(timer)
      document.removeEventListener('visibilitychange', onVisible)
      controller.current?.abort()
    }
  }, [load, enabled])

  const markRead = useCallback(
    (ids: string[]) => {
      if (ids.length === 0) return
      setData((d) => {
        if (!d) return d
        const messages = d.messages.map((m) => (ids.includes(m.id) ? { ...m, read: true } : m))
        return { ...d, messages, unread: messages.filter((m) => !m.read).length }
      })
      // En cas d'échec, la relecture remet l'état du serveur.
      markNotificationsRead(slug, ids).catch(load)
    },
    [slug, load],
  )

  return { data, error, markRead }
}

const titleOf = (message: NotificationView, topics: NotificationsView['topics']) => {
  const emoji = message.tags.map((t) => EMOJIS[t]).find(Boolean)
  const title = message.title ?? topics.find((t) => t.id === message.topic)?.name ?? message.topic
  return emoji ? `${emoji} ${title}` : title
}

/** Bandeau en haut de la page pour les messages urgents (priorité 5) pas encore lus. */
export function UrgentBanner({ notifications, now }: { notifications: NotificationsState; now?: Date }) {
  const urgent = notifications.data?.messages.filter((m) => m.priority >= 5 && !m.read) ?? []
  if (urgent.length === 0) return null

  return (
    <div role="alert" className="flex flex-col gap-2 rounded-2xl bg-red-600 px-4 py-3 text-white shadow-lg">
      {urgent.map((m) => (
        <div key={m.id} className="flex items-center gap-3">
          <div className="min-w-0 flex-1">
            <p className="font-semibold">{titleOf(m, notifications.data!.topics)}</p>
            <p className="text-sm text-red-50">
              {m.message} · {formatRelative(m.time, now ?? new Date())}
            </p>
          </div>
          <button
            type="button"
            onClick={() => notifications.markRead([m.id])}
            className="rounded-lg bg-white/20 px-3 py-1.5 text-sm font-medium hover:bg-white/30 focus-visible:outline-2 focus-visible:outline-white"
          >
            Vu
          </button>
        </div>
      ))}
    </div>
  )
}

/** Cloche de l'en-tête (nombre de messages non lus) et historique en panneau latéral (plein écran sur téléphone). */
export function NotificationBell({ notifications, now }: { notifications: NotificationsState; now?: Date }) {
  const [open, setOpen] = useState(false)
  const { data, error, markRead } = notifications
  const unread = data?.unread ?? 0
  const label = error && !data ? 'Notifications : indisponibles' : `Notifications : ${unread === 0 ? 'aucune non lue' : unread === 1 ? '1 non lue' : `${unread} non lues`}`

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open])

  return (
    <>
      <button
        type="button"
        aria-label={label}
        title={label}
        onClick={() => setOpen(true)}
        className="relative grid size-9 place-items-center rounded-lg text-zinc-600 hover:bg-zinc-200 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800"
      >
        <BellIcon className="size-5" />
        {unread > 0 && (
          <span aria-hidden="true" className="absolute -top-0.5 -right-0.5 grid h-4.5 min-w-4.5 place-items-center rounded-full bg-red-600 px-1 text-[11px] leading-none font-semibold text-white">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
        {error && !data && <span aria-hidden="true" className="absolute top-1 right-1 size-2 rounded-full bg-amber-500" />}
      </button>

      {open && (
        <div className="fixed inset-0 z-40 flex justify-end">
          <div className="absolute inset-0 bg-zinc-950/30 dark:bg-black/50" onClick={() => setOpen(false)} aria-hidden="true" />
          <div
            role="dialog"
            aria-modal="true"
            aria-label="Notifications"
            className="relative flex h-full w-full flex-col bg-zinc-50 shadow-xl sm:max-w-md dark:bg-slate-900"
          >
            <div className="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-slate-800">
              <h2 className="flex-1 text-lg font-semibold text-zinc-900 dark:text-slate-50">Notifications</h2>
              {unread > 0 && (
                <button
                  type="button"
                  onClick={() => markRead(data!.messages.filter((m) => !m.read).map((m) => m.id))}
                  className="rounded-lg px-2 py-1 text-sm text-indigo-600 hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-indigo-400 dark:hover:bg-slate-800"
                >
                  Tout marquer comme lu
                </button>
              )}
              <button
                type="button"
                aria-label="Fermer"
                onClick={() => setOpen(false)}
                className="grid size-8 place-items-center rounded-lg text-zinc-500 hover:bg-zinc-200 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800"
              >
                <CloseIcon className="size-5" />
              </button>
            </div>

            <div className="flex-1 overflow-y-auto px-4 py-3">
              {error && <p className="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-900">{error.message}</p>}
              {data && data.messages.length === 0 && <p className="py-8 text-center text-sm text-zinc-500 dark:text-slate-400">Aucun message ces 14 derniers jours.</p>}
              {data && <MessageList view={data} now={now ?? new Date()} onRead={(id) => markRead([id])} />}
            </div>

            {data?.link && (
              <div className="border-t border-zinc-200 px-4 py-2 text-right text-sm dark:border-slate-800">
                <ExternalLink href={data.link} className="text-zinc-500 hover:text-zinc-900 dark:text-slate-400 dark:hover:text-slate-100">
                  Ouvrir ntfy
                </ExternalLink>
              </div>
            )}
          </div>
        </div>
      )}
    </>
  )
}

const dayLabel = (iso: string, now: Date) => {
  const day = (d: Date) => d.toLocaleDateString('fr-FR', { timeZone: 'Europe/Paris' })
  const date = new Date(iso)
  if (day(date) === day(now)) return 'Aujourd’hui'
  if (day(date) === day(new Date(now.getTime() - 86_400_000))) return 'Hier'
  return date.toLocaleDateString('fr-FR', { timeZone: 'Europe/Paris', weekday: 'long', day: 'numeric', month: 'long' })
}

const priorityRing: Record<number, string> = {
  5: 'border-l-red-500',
  4: 'border-l-amber-500',
}

function MessageList({ view, now, onRead }: { view: NotificationsView; now: Date; onRead: (id: string) => void }) {
  const days: { label: string; messages: NotificationView[] }[] = []
  for (const m of view.messages) {
    const label = dayLabel(m.time, now)
    if (days.at(-1)?.label !== label) days.push({ label, messages: [] })
    days.at(-1)!.messages.push(m)
  }
  const topicName = (id: string) => view.topics.find((t) => t.id === id)?.name ?? id

  return days.map((day) => (
    <section key={day.label} className="mb-4">
      <h3 className="mb-2 text-xs font-semibold tracking-wide text-zinc-500 uppercase first-letter:uppercase dark:text-slate-400">{day.label}</h3>
      <ul className="flex flex-col gap-2">
        {day.messages.map((m) => (
          <li
            key={m.id}
            className={`rounded-xl border-l-4 bg-white ring-1 ring-zinc-200 dark:bg-slate-800 dark:ring-slate-700 ${priorityRing[m.priority] ?? (m.read ? 'border-l-transparent' : 'border-l-indigo-500')}`}
          >
            <button
              type="button"
              onClick={() => !m.read && onRead(m.id)}
              className={`block w-full px-3 pt-2 text-left focus-visible:outline-2 focus-visible:outline-indigo-500 ${m.read ? 'opacity-70' : ''} ${m.click ? 'pb-1' : 'pb-2'}`}
            >
              <h4 className={`text-sm text-zinc-900 dark:text-slate-50 ${m.read ? '' : 'font-semibold'}`}>{titleOf(m, view.topics)}</h4>
              {m.message && <p className="text-sm whitespace-pre-line text-zinc-700 dark:text-slate-300">{m.message}</p>}
              <p className="mt-1 text-xs text-zinc-500 dark:text-slate-400">
                {topicName(m.topic)} · {formatRelative(m.time, now)}
                {!m.read && <span className="sr-only"> · non lu</span>}
              </p>
            </button>
            {m.click && (
              <div className="px-3 pb-2">
                <ExternalLink href={m.click} onClick={() => !m.read && onRead(m.id)} className="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                  Ouvrir
                </ExternalLink>
              </div>
            )}
          </li>
        ))}
      </ul>
    </section>
  ))
}
