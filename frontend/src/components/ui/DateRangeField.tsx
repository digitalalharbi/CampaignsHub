import { useCallback, useRef, useState } from 'react'
import { CalendarDays } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { PickerPanel } from '@/components/ui/DateRangePicker'
import { formatPeriodRange } from '@/lib/period/formatPeriodLabel'
import { isValidYmd, riyadhToday } from '@/lib/period/periodMath'
import { resolveSelection } from '@/lib/period/periodState'

/**
 * DATE-RANGE-001 — the same panel as the page period, for a period that belongs to a DOCUMENT (a report, a link).
 *
 * It does not touch the page's period or its address: a report keeps its own dates. It is the one picker, so
 * the calendar, the typed fields, the presets and the Riyadh footer behave exactly as everywhere else; only the
 * comparison is left out, because a saved report states one period.
 */
export function DateRangeField({ from, to, onChange, id, testId = 'range-field' }: {
  from: string
  to: string
  onChange: (from: string, to: string) => void
  id?: string
  testId?: string
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLButtonElement>(null)
  const close = useCallback(() => { setOpen(false); ref.current?.focus() }, [])
  const ctx = { today: riyadhToday() }
  const valid = isValidYmd(from) && isValidYmd(to)

  return (
    <div>
      <button
        ref={ref} id={id} type="button" data-testid={`${testId}-button`} aria-haspopup="dialog" aria-expanded={open} onClick={() => setOpen((v) => !v)}
        className="flex min-h-11 w-full items-center gap-2 rounded-xl border border-border-strong bg-surface px-3 py-1.5 text-start text-sm font-semibold text-text-primary hover:bg-surface-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 sm:min-h-10"
      >
        <CalendarDays className="h-4 w-4 shrink-0 text-text-secondary" aria-hidden />
        <span className="truncate" data-testid={`${testId}-summary`}>{valid ? formatPeriodRange(from, to, ar ? 'ar' : 'en') : (ar ? 'اختر الفترة' : 'Choose the period')}</span>
      </button>
      {open && (
        <PickerPanel
          ar={ar} anchor={ref} allowCompare={false} testId={testId} ctx={ctx} recent={[]}
          initial={valid ? { key: 'custom', from, to, compare: 'none' } : { key: 'last_30', compare: 'none' }}
          onCancel={close}
          onApply={(sel) => { const p = resolveSelection(sel, ctx).period; onChange(p.from, p.to); close() }}
        />
      )}
    </div>
  )
}
