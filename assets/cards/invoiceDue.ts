/** late = en retard, soon = échéance dans soon_days jours ou moins, unknown = sans échéance. */
export type DueTone = 'late' | 'soon' | 'later' | 'unknown'

export type InvoiceDue = { tone: DueTone; label: string; days: number | null }

const short = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' })
const withYear = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })

/**
 * Échéance « AAAA-MM-JJ » d'une facture, vue depuis le jour de `now` (heure
 * locale) : « en retard de 4 j », « aujourd’hui », « demain », « dans 3 j »,
 * ou la date courte au-delà de soonDays.
 */
export function invoiceDue(dueDate: string | null, now: Date, soonDays: number): InvoiceDue {
  if (dueDate === null) return { tone: 'unknown', label: '?', days: null }

  const [y, m, d] = dueDate.split('-').map(Number)
  const due = new Date(y!, m! - 1, d!)
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  // Arrondi : un jour de changement d'heure dure 23 ou 25 h.
  const days = Math.round((due.getTime() - today.getTime()) / 86_400_000)

  if (days < 0) return { tone: 'late', label: `en retard de ${-days} j`, days }
  const tone = days <= soonDays ? 'soon' : 'later'
  if (days === 0) return { tone, label: 'aujourd’hui', days }
  if (days === 1) return { tone, label: 'demain', days }
  if (tone === 'soon') return { tone, label: `dans ${days} j`, days }

  return { tone, label: (due.getFullYear() === today.getFullYear() ? short : withYear).format(due), days }
}

const amounts = new Map<string, Intl.NumberFormat>()

/** « 246,50 € » ; « ? » si le montant n'est pas connu. */
export function formatAmount(amount: number | null, currency: string): string {
  if (amount === null) return '?'
  let format = amounts.get(currency)
  if (!format) {
    try {
      format = new Intl.NumberFormat('fr-FR', { style: 'currency', currency })
    } catch {
      // Code de devise que le navigateur ne connaît pas.
      return `${new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount)} ${currency}`
    }
    amounts.set(currency, format)
  }

  return format.format(amount)
}
