import { useMemo, type KeyboardEvent, type PointerEvent } from 'react'
import {
  MONTHS_AR, MONTHS_EN, WEEKDAYS_AR, WEEKDAYS_EN, addDays, daysInMonth, formatDay, weekday, ymd, type DateSpan,
} from '@/lib/period/periodMath'

/**
 * DATE-RANGE-001 — one month of days, with up to two ranges painted on it.
 *
 * The week starts on Sunday. In a right-to-left page the grid flows right to left on its own, so Sunday
 * sits at the right edge, which is how an Arabic calendar reads.
 *
 * Two ranges are shown in two colours AND two shapes (the period is filled, the comparison is outlined),
 * so they stay apart for a reader who cannot tell the colours apart. Days after today are disabled: a
 * period cannot hold days that have not happened.
 *
 * Touch targets are 44px on a phone. The component only reports what was pointed at; the picker owns the
 * meaning of a click, a drag and a keystroke.
 */
export interface Paint { span: DateSpan | null; tone: 'main' | 'compare' }

export function RangeCalendar({
  year, month, today, paints, ar, onDayClick, onDayPointerDown, onDayPointerEnter, onDayPointerUp, onDayKeyDown, testId,
}: {
  year: number
  month: number
  today: string
  paints: Paint[]
  ar: boolean
  onDayClick: (day: string) => void
  onDayPointerDown: (day: string, e: PointerEvent) => void
  onDayPointerEnter: (day: string) => void
  onDayPointerUp: (day: string, e: PointerEvent) => void
  onDayKeyDown: (day: string, e: KeyboardEvent) => void
  testId?: string
}) {
  const cells = useMemo(() => {
    const first = ymd(year, month, 1)
    const lead = weekday(first)
    const out: Array<string | null> = Array.from({ length: lead }, () => null)
    for (let d = 1; d <= daysInMonth(year, month); d++) out.push(ymd(year, month, d))

    return out
  }, [year, month])

  const names = ar ? WEEKDAYS_AR : WEEKDAYS_EN
  const inSpan = (s: DateSpan | null, day: string) => s !== null && day >= s.from && day <= s.to

  return (
    <div className="min-w-0" data-testid={testId}>
      <div className="mb-0.5 text-center text-xs font-bold text-text-primary" aria-hidden>
        {(ar ? MONTHS_AR : MONTHS_EN)[month - 1]} <bdi dir="ltr">{year}</bdi>
      </div>
      <div className="grid grid-cols-7 text-center text-xs font-semibold text-text-muted" aria-hidden>
        {names.map((n) => <div key={n} className="py-0.5 text-[10px] sm:text-[10px]">{n}</div>)}
      </div>
      <div role="grid" aria-label={`${(ar ? MONTHS_AR : MONTHS_EN)[month - 1]} ${year}`} className="grid grid-cols-7 gap-y-0.5">
        {cells.map((day, i) => {
          if (day === null) return <div key={`b${i}`} role="gridcell" />
          const future = day > today
          const main = paints.find((p) => p.tone === 'main')?.span ?? null
          const cmp = paints.find((p) => p.tone === 'compare')?.span ?? null
          const isMain = inSpan(main, day)
          const isCmp = inSpan(cmp, day)
          const mainEdge = isMain && (day === main!.from || day === main!.to)
          const cmpEdge = isCmp && (day === cmp!.from || day === cmp!.to)
          const dayNum = Number(day.slice(8))

          let cls = 'text-text-primary hover:bg-surface-hover'
          if (isCmp) cls = 'border-y border-dashed border-info bg-[var(--info-background)] text-info'
          if (isMain) cls = mainEdge ? 'bg-brand-600 text-white' : 'bg-brand-100 text-brand-700'
          if (isMain && isCmp && !mainEdge) cls += ' ring-1 ring-inset ring-info'
          if (future) cls = 'cursor-not-allowed text-text-muted/50'

          const edgeShape = (isMain && mainEdge) || (isCmp && cmpEdge) ? 'rounded-lg' : isMain || isCmp ? 'rounded-none' : 'rounded-lg'

          return (
            <div key={day} role="gridcell" aria-selected={isMain || isCmp} className="px-0">
              <button
                type="button"
                data-day={day}
                data-testid={`day-${day}`}
                data-state={isMain ? 'period' : isCmp ? 'compare' : 'none'}
                disabled={future}
                aria-label={formatDay(day, ar)}
                aria-pressed={isMain || isCmp}
                tabIndex={day === (main?.from ?? today) || (!main && day === today) ? 0 : -1}
                onClick={() => onDayClick(day)}
                onPointerDown={(e) => onDayPointerDown(day, e)}
                onPointerEnter={() => onDayPointerEnter(day)}
                onPointerUp={(e) => onDayPointerUp(day, e)}
                onKeyDown={(e) => onDayKeyDown(day, e)}
                className={`relative flex h-11 w-full select-none items-center justify-center text-sm font-semibold tnum sm:h-7 sm:text-xs ${edgeShape} ${cls}`}
              >
                <bdi dir="ltr">{dayNum}</bdi>
                {day === today && <span aria-hidden className="absolute bottom-0.5 h-1 w-1 rounded-full bg-current" />}
              </button>
            </div>
          )
        })}
      </div>
    </div>
  )
}

/** Move a day by a keyboard arrow; the grid is a calendar, so up/down is a week. RTL swaps left and right. */
export function stepForKey(key: string, rtl: boolean): number | null {
  switch (key) {
    case 'ArrowLeft': return rtl ? 1 : -1
    case 'ArrowRight': return rtl ? -1 : 1
    case 'ArrowUp': return -7
    case 'ArrowDown': return 7
    default: return null
  }
}

export { addDays }
