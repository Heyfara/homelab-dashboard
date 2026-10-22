import { ApiError } from '../http/client'

export function ErrorMessage({ error }: { error: Error }) {
  const details = error instanceof ApiError ? error.details : []

  return (
    <div role="alert" className="rounded-2xl bg-rose-50 p-4 text-sm text-rose-900 ring-1 ring-rose-200 dark:bg-rose-950 dark:text-rose-200 dark:ring-rose-900">
      <p className="font-medium">{error.message}</p>
      {details.length > 0 && (
        <ul className="mt-2 list-disc space-y-1 pl-5">
          {details.map((d) => (
            <li key={d}>{d}</li>
          ))}
        </ul>
      )}
    </div>
  )
}
