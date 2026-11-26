import { CalendarSearch } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { usePeriod } from '@/lib/period/usePeriod'

/**
 * STORE-LATEST-DATA-001 — a connected store with nothing in the chosen period is not a blank page: it says
 * when the store last sold and opens that month in one tap. Without a latest order it is the plain empty line.
 */
export function StoreLatestJump({
  latest,
  ar,
  testId,
}: {
  latest: { date: string; from: string; to: string } | null | undefined
  ar: boolean
  testId: string
}) {
  const { setSelection } = usePeriod()

  if (!latest) {
    return (
      <p data-testid={testId} className="rounded-xl bg-surface-hover px-4 py-3 text-sm text-text-secondary">
        {ar ? 'لم يسجّل المتجر أي طلب بعد.' : 'The store has not recorded an order yet.'}
      </p>
    )
  }

  const month = new Date(`${latest.from}T00:00:00`).toLocaleDateString(ar ? 'ar-SA-u-nu-latn-ca-gregory' : 'en-GB', {
    month: 'long',
    year: 'numeric',
  })
  const day = new Date(`${latest.date}T00:00:00`).toLocaleDateString(ar ? 'ar-SA-u-nu-latn-ca-gregory' : 'en-GB', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })

  return (
    <div
      data-testid={testId}
      className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-surface-hover px-4 py-3 text-sm"
    >
      <span className="text-text-secondary">
        {ar ? 'لا طلبات في هذه الفترة. آخر طلب في المتجر: ' : 'No orders in this period. The store’s latest order: '}
        <span className="font-semibold text-text-primary" data-testid={`${testId}-date`}>{day}</span>
      </span>
      <Button
        size="sm"
        variant="secondary"
        data-testid={`${testId}-jump`}
        onClick={() => setSelection({ key: 'custom', from: latest.from, to: latest.to, compare: 'none' })}
      >
        <CalendarSearch className="size-4" aria-hidden />
        {ar ? `عرض ${month}` : `Show ${month}`}
      </Button>
    </div>
  )
}
