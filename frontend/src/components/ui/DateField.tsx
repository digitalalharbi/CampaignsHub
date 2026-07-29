import { useRef } from 'react'
import { CalendarDays } from 'lucide-react'
import { controlClass } from './Field'

interface DateFieldProps {
  id?: string
  value: string // ISO YYYY-MM-DD, or YYYY-MM-DDTHH:mm when withTime (or '' when empty)
  onChange: (value: string) => void
  className?: string
  required?: boolean
  min?: string
  max?: string
  placeholder?: string
  /** Include a time part (native datetime-local picker; stored value stays `YYYY-MM-DDTHH:mm`). */
  withTime?: boolean
  'aria-label'?: string
}

/**
 * Locale-independent date field.
 *
 * Native `<input type="date">` is localized by the *browser* locale (not the element `lang`), so under an
 * Arabic browser it renders garbled Arabic day/month/year sub-fields ("طلاسم"). This control instead shows a
 * plain `YYYY-MM-DD` text box (always Gregorian English) and keeps the native calendar via a visually-hidden
 * date input opened with `showPicker()`. The stored value is always ISO `YYYY-MM-DD`.
 */
export function DateField({
  id, value, onChange, className = '', required, min, max, placeholder, withTime, ...rest
}: DateFieldProps) {
  const picker = useRef<HTMLInputElement>(null)

  const openPicker = () => {
    const el = picker.current
    if (!el) return
    // showPicker() is supported in modern Chromium/Safari/Firefox; fall back to focus+click.
    if (typeof el.showPicker === 'function') el.showPicker()
    else el.focus()
  }

  // The text box always reads left-to-right in a clear English form; a space separates date and time.
  const display = withTime ? value.replace('T', ' ') : value
  const ph = placeholder ?? (withTime ? 'YYYY-MM-DD HH:mm' : 'YYYY-MM-DD')
  const pattern = withTime ? '\\d{4}-\\d{2}-\\d{2}[ T]\\d{2}:\\d{2}' : '\\d{4}-\\d{2}-\\d{2}'
  const fromText = (raw: string) => onChange(withTime ? raw.replace(' ', 'T') : raw)

  return (
    <div className="relative flex w-full items-center">
      <input
        id={id}
        type="text"
        inputMode="numeric"
        dir="ltr"
        lang="en"
        value={display}
        required={required}
        placeholder={ph}
        pattern={pattern}
        aria-label={rest['aria-label']}
        onChange={(e) => fromText(e.target.value)}
        className={`${controlClass} pe-9 text-start ${className}`}
      />
      <button
        type="button"
        onClick={openPicker}
        aria-label="Open calendar"
        tabIndex={-1}
        className="absolute end-2 flex items-center text-text-muted transition-colors hover:text-text-primary"
      >
        <CalendarDays size={16} />
      </button>
      {/* Native calendar source — visually hidden; only its popup is used. */}
      <input
        ref={picker}
        type={withTime ? 'datetime-local' : 'date'}
        tabIndex={-1}
        aria-hidden
        value={value}
        min={min}
        max={max}
        onChange={(e) => onChange(e.target.value)}
        className="pointer-events-none absolute bottom-0 end-2 h-0 w-0 opacity-0"
      />
    </div>
  )
}
