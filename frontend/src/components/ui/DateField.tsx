import { useEffect, useMemo, useRef, useState } from 'react'
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react'
import { controlClass } from './Field'

interface DateFieldProps {
  id?: string
  value: string // ISO YYYY-MM-DD, or YYYY-MM-DDTHH:mm when withTime (or '' when empty)
  onChange: (value: string) => void
  className?: string
  required?: boolean
  placeholder?: string
  /** Include a time part; the popover picks the date, the time stays editable as text (HH:mm). */
  withTime?: boolean
  'aria-label'?: string
}

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]
const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']

const pad = (n: number) => String(n).padStart(2, '0')
const toISO = (y: number, m: number, d: number) => `${y}-${pad(m + 1)}-${pad(d)}`

/** Parse the date part of a YYYY-MM-DD[ T HH:mm] string into {y, m0, d}, or null. */
function parseDate(v: string): { y: number; m0: number; d: number } | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(v)
  if (!match) return null
  const y = Number(match[1]); const m0 = Number(match[2]) - 1; const d = Number(match[3])
  if (m0 < 0 || m0 > 11 || d < 1 || d > 31) return null
  return { y, m0, d }
}

/**
 * Professional, locale-independent date picker.
 *
 * The visible box always reads Gregorian English `YYYY-MM-DD` (LTR, even under the Arabic UI) — never the
 * browser-localized native date sub-fields, which render garbled Arabic. The calendar popover has explicit
 * month + year selectors and a day grid. Value stays ISO `YYYY-MM-DD` (or `YYYY-MM-DDTHH:mm` with time).
 */
export function DateField({
  id, value, onChange, className = '', required, placeholder, withTime, ...rest
}: DateFieldProps) {
  const [open, setOpen] = useState(false)
  const root = useRef<HTMLDivElement>(null)

  const parsed = parseDate(value)
  const today = new Date()
  const [viewY, setViewY] = useState(parsed?.y ?? today.getFullYear())
  const [viewM, setViewM] = useState(parsed?.m0 ?? today.getMonth())

  // When the popover opens, jump the view to the selected month (or today).
  useEffect(() => {
    if (!open) return
    const p = parseDate(value)
    setViewY(p?.y ?? today.getFullYear())
    setViewM(p?.m0 ?? today.getMonth())
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  // Close on outside click / Escape.
  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => { if (root.current && !root.current.contains(e.target as Node)) setOpen(false) }
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey) }
  }, [open])

  const years = useMemo(() => {
    const base = parsed?.y ?? today.getFullYear()
    const start = Math.min(base, today.getFullYear()) - 10
    return Array.from({ length: 25 }, (_, i) => start + i)
  }, [parsed?.y, today])

  const timePart = withTime ? (value.split('T')[1]?.slice(0, 5) ?? '') : ''
  const display = withTime ? value.replace('T', ' ') : value
  const ph = placeholder ?? (withTime ? 'YYYY-MM-DD HH:mm' : 'YYYY-MM-DD')

  const commitDate = (iso: string) => {
    onChange(withTime ? `${iso}T${timePart || '00:00'}` : iso)
    setOpen(false)
  }
  const fromText = (raw: string) => onChange(withTime ? raw.replace(' ', 'T') : raw)

  // Build the 6×7 day grid for the viewed month.
  const firstWeekday = new Date(viewY, viewM, 1).getDay()
  const daysInMonth = new Date(viewY, viewM + 1, 0).getDate()
  const cells: (number | null)[] = [
    ...Array.from({ length: firstWeekday }, () => null),
    ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
  ]
  while (cells.length % 7 !== 0) cells.push(null)

  const isSelected = (d: number) => parsed != null && parsed.y === viewY && parsed.m0 === viewM && parsed.d === d
  const isToday = (d: number) =>
    today.getFullYear() === viewY && today.getMonth() === viewM && today.getDate() === d

  return (
    <div ref={root} className="relative flex w-full items-center">
      <input
        id={id}
        type="text"
        inputMode="numeric"
        dir="ltr"
        lang="en"
        value={display}
        required={required}
        placeholder={ph}
        pattern={withTime ? '\\d{4}-\\d{2}-\\d{2}[ T]\\d{2}:\\d{2}' : '\\d{4}-\\d{2}-\\d{2}'}
        aria-label={rest['aria-label']}
        onChange={(e) => fromText(e.target.value)}
        onFocus={() => setOpen(true)}
        className={`${controlClass} pe-9 text-start ${className}`}
      />
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label="Open calendar"
        aria-expanded={open}
        tabIndex={-1}
        className="absolute end-2 flex items-center text-text-muted transition-colors hover:text-text-primary"
      >
        <CalendarDays size={16} />
      </button>

      {open && (
        <div
          dir="ltr"
          className="absolute top-full z-50 mt-1 w-[268px] rounded-2xl border border-border bg-surface p-3 shadow-[var(--shadow-large)]"
          style={{ insetInlineStart: 0 }}
        >
          {/* Month / year controls */}
          <div className="mb-2 flex items-center gap-1.5">
            <button
              type="button" aria-label="Previous month"
              onClick={() => { const m = viewM - 1; if (m < 0) { setViewM(11); setViewY((y) => y - 1) } else setViewM(m) }}
              className="flex h-7 w-7 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover"
            ><ChevronLeft size={16} /></button>
            <select
              value={viewM} onChange={(e) => setViewM(Number(e.target.value))}
              className="h-8 flex-1 rounded-lg border border-border bg-surface px-2 text-sm font-semibold"
            >
              {MONTHS.map((mn, i) => <option key={mn} value={i}>{mn}</option>)}
            </select>
            <select
              value={viewY} onChange={(e) => setViewY(Number(e.target.value))}
              className="tnum h-8 rounded-lg border border-border bg-surface px-2 text-sm font-semibold"
            >
              {years.map((y) => <option key={y} value={y}>{y}</option>)}
            </select>
            <button
              type="button" aria-label="Next month"
              onClick={() => { const m = viewM + 1; if (m > 11) { setViewM(0); setViewY((y) => y + 1) } else setViewM(m) }}
              className="flex h-7 w-7 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover"
            ><ChevronRight size={16} /></button>
          </div>

          {/* Weekday header */}
          <div className="mb-1 grid grid-cols-7 gap-0.5 text-center text-[11px] font-semibold text-text-muted">
            {WEEKDAYS.map((w) => <span key={w}>{w}</span>)}
          </div>

          {/* Day grid */}
          <div className="grid grid-cols-7 gap-0.5">
            {cells.map((d, i) =>
              d === null ? (
                <span key={`e${i}`} />
              ) : (
                <button
                  key={d}
                  type="button"
                  onClick={() => commitDate(toISO(viewY, viewM, d))}
                  className={`tnum flex h-8 items-center justify-center rounded-lg text-sm transition-colors ${
                    isSelected(d)
                      ? 'bg-brand-600 font-bold text-white'
                      : isToday(d)
                        ? 'font-bold text-brand-600 hover:bg-surface-hover'
                        : 'text-text-primary hover:bg-surface-hover'
                  }`}
                >
                  {d}
                </button>
              ),
            )}
          </div>

          {withTime && (
            <div className="mt-2 flex items-center gap-2 border-t border-border pt-2">
              <span className="text-xs font-semibold text-text-secondary">Time</span>
              <input
                type="text" inputMode="numeric" dir="ltr" placeholder="HH:mm"
                value={timePart}
                pattern="\d{2}:\d{2}"
                onChange={(e) => {
                  const t = e.target.value
                  const datePart = value.split('T')[0] || toISO(viewY, viewM, parsed?.d ?? today.getDate())
                  onChange(`${datePart}T${t}`)
                }}
                className="h-8 w-24 rounded-lg border border-border bg-surface px-2 text-sm"
              />
            </div>
          )}
        </div>
      )}
    </div>
  )
}
