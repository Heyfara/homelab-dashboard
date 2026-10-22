import type { CardComponent } from './registry'
import { ExternalLink } from '../ui/ExternalLink'
import { formatNumber } from '../ui/format'
import { ClockIcon, StarIcon } from '../ui/icons'
import { MediaSlider } from '../ui/MediaSlider'
import { MediaTile } from '../ui/MediaTile'
import { ServiceIcon } from '../ui/ServiceIcon'

/** Props de MealieCard::describe() côté PHP. */
export type MealieProps = { title: string; service: string; url: string; icon: string | null }

export type MealieRecipeItem = { id: string; name: string; image: string | null; link: string; totalTime: string | null; rating: number | null }

/** Données de MealieCard::fetch() côté PHP. */
export type MealieData = {
  today: string
  idea: MealieRecipeItem | null
  plan: { date: string; type: string; title: string; recipe: MealieRecipeItem | null }[]
  recipes: MealieRecipeItem[]
  total: number
}

const MEALS: Record<string, string> = {
  breakfast: 'Petit-déjeuner',
  lunch: 'Déjeuner',
  dinner: 'Dîner',
  side: 'Accompagnement',
  snack: 'En-cas',
  dessert: 'Dessert',
  drink: 'Boisson',
}

/** Entrées du planning affichées au plus : la place reste aux recettes. */
const MAX_PLAN = 3

const weekday = new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', timeZone: 'UTC' })

/** « Aujourd'hui », « Demain », « jeu. 22 ». */
function dayLabel(date: string, today: string): string {
  const days = Math.round((Date.parse(`${date}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) / 86_400_000)
  if (days === 0) return "Aujourd'hui"
  if (days === 1) return 'Demain'
  return weekday.format(new Date(`${date}T00:00:00Z`))
}

export const MealieCard: CardComponent<MealieProps, MealieData> = ({ card, result }) => {
  const { title, service, url, icon } = card.props
  const data = result?.status === 'ok' ? result.data : null

  return (
    <div className="flex h-full flex-col gap-3">
      <header className="flex items-center gap-2">
        <span className="size-7 shrink-0 [&>*]:size-7 [&>span]:rounded-lg [&>span]:text-xs">
          <ServiceIcon icon={icon} name={service} />
        </span>
        <h3 className="min-w-0 truncate font-semibold text-zinc-900 dark:text-slate-50">{card.title ?? title}</h3>
        {data && <span className="shrink-0 text-xs text-zinc-500 tabular-nums dark:text-slate-400">{formatNumber(data.total)} recettes</span>}
        <span className="flex-1" />
        <ExternalLink href={url} className="shrink-0 rounded-md px-2 py-0.5 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
          Ouvrir {service} →
        </ExternalLink>
      </header>

      {data === null ? (
        <div aria-busy="true" className="flex min-h-0 flex-1 flex-col gap-3">
          <div className="h-24 animate-pulse rounded-xl bg-zinc-100 dark:bg-slate-800" />
          <div className="min-h-0 flex-1 animate-pulse rounded-lg bg-zinc-100 dark:bg-slate-800" />
        </div>
      ) : (
        <>
          {data.idea && <Idea recipe={data.idea} />}
          {data.plan.length > 0 && <Plan plan={data.plan} today={data.today} />}
          <MediaSlider
            label="Recettes"
            previousLabel="Recettes précédentes"
            nextLabel="Recettes suivantes"
            aspect="aspect-[4/3]"
            items={data.recipes}
            itemKey={(r) => r.id}
            renderItem={(r) => <MediaTile href={r.link} image={r.image} title={r.name} subtitle={r.totalTime} />}
          />
        </>
      )}
    </div>
  )
}

function Idea({ recipe }: { recipe: MealieRecipeItem }) {
  return (
    <ExternalLink
      href={recipe.link}
      aria-label={`Idée recette du jour : ${recipe.name}`}
      className="group flex shrink-0 items-center gap-3 overflow-hidden rounded-xl bg-orange-500/8 p-2 ring-1 ring-orange-500/15 hover:bg-orange-500/12 focus-visible:outline-2 focus-visible:outline-indigo-500"
    >
      <span className="aspect-[4/3] h-20 shrink-0 overflow-hidden rounded-lg bg-zinc-200 dark:bg-slate-800">
        {recipe.image && <img src={recipe.image} alt="" draggable={false} className="size-full object-cover transition duration-300 group-hover:scale-105 motion-reduce:transition-none" />}
      </span>
      <span className="flex min-w-0 flex-col gap-1">
        <span className="text-[0.65rem] font-semibold tracking-wider text-orange-700 uppercase dark:text-orange-300">Idée recette du jour</span>
        <span className="line-clamp-2 leading-tight font-semibold text-zinc-900 dark:text-slate-50">{recipe.name}</span>
        <span className="flex items-center gap-3 text-xs text-zinc-500 dark:text-slate-400">
          {recipe.totalTime && (
            <span className="flex items-center gap-1">
              <ClockIcon className="size-3" />
              {recipe.totalTime}
            </span>
          )}
          {recipe.rating !== null && <Rating value={recipe.rating} />}
        </span>
      </span>
    </ExternalLink>
  )
}

function Rating({ value }: { value: number }) {
  return (
    <span className="flex items-center" aria-label={`Note : ${formatNumber(value)} sur 5`} role="img">
      {[1, 2, 3, 4, 5].map((i) => (
        <StarIcon key={i} filled={value >= i - 0.25} className={`size-3 ${value >= i - 0.25 ? 'text-yellow-500' : 'text-zinc-300 dark:text-slate-600'}`} />
      ))}
    </span>
  )
}

function Plan({ plan, today }: { plan: MealieData['plan']; today: string }) {
  return (
    <section aria-label="Au menu" className="flex shrink-0 flex-col gap-1">
      <h4 className="text-[0.65rem] font-semibold tracking-wider text-zinc-500 uppercase dark:text-slate-400">Au menu</h4>
      <ul className="flex flex-col gap-0.5 text-sm">
        {plan.slice(0, MAX_PLAN).map((meal, i) => (
          <li key={`${meal.date}-${meal.type}-${i}`} className="flex min-w-0 items-baseline gap-2">
            <span className="w-24 shrink-0 text-xs text-zinc-500 dark:text-slate-400">{dayLabel(meal.date, today)}</span>
            <span className="w-24 shrink-0 text-xs font-medium text-orange-700 dark:text-orange-300">{MEALS[meal.type] ?? meal.type}</span>
            {meal.recipe ? (
              <ExternalLink href={meal.recipe.link} className="min-w-0 truncate text-zinc-800 hover:underline dark:text-slate-100">
                {meal.title}
              </ExternalLink>
            ) : (
              <span className="min-w-0 truncate text-zinc-800 dark:text-slate-100">{meal.title}</span>
            )}
          </li>
        ))}
      </ul>
    </section>
  )
}
