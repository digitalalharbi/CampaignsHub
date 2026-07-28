import { forwardRef, type ReactNode } from 'react'
import { AlertTriangle, Loader2, RefreshCw, Search, X } from 'lucide-react'
import { controlClass } from '@/components/ui/Field'
import type { FormsCopy, Option } from './types'

/** Trigger styling shared by every select-style control — mirrors the app's input control exactly. */
export const triggerClass = `${controlClass} flex items-center gap-2 text-start`

/** Floating listbox panel (RTL-safe: spans the trigger width via inset-x). */
export const panelClass =
  'absolute inset-x-0 top-full z-50 mt-1.5 flex flex-col overflow-hidden rounded-[12px] ' +
  'border border-border bg-surface shadow-[var(--shadow-large)]'

/** Small spinner used inline in triggers and panel headers. */
export function Spinner({ size = 14 }: { size?: number }) {
  return <Loader2 size={size} className="animate-spin text-text-muted" aria-hidden />
}

/** Leading swatch: a color dot or an icon glyph, whichever the option carries. */
export function OptionSwatch({ option }: { option: Option }) {
  if (option.color) {
    return (
      <span
        aria-hidden
        className="inline-block h-2.5 w-2.5 shrink-0 rounded-full"
        style={{ backgroundColor: option.color }}
      />
    )
  }
  if (option.icon) {
    return (
      <span aria-hidden className="inline-flex h-4 w-4 shrink-0 items-center justify-center text-[13px] leading-none">
        {option.icon}
      </span>
    )
  }
  return null
}

/** The in-panel search box (RTL-safe icon placement via logical padding). */
export const PanelSearch = forwardRef<HTMLInputElement, {
  value: string
  onChange: (v: string) => void
  onKeyDown?: (e: React.KeyboardEvent) => void
  placeholder: string
  ariaControls?: string
  ariaActiveDescendant?: string
}>(function PanelSearch({ value, onChange, onKeyDown, placeholder, ariaControls, ariaActiveDescendant }, ref) {
  return (
    <div className="relative border-b border-border">
      <Search
        size={15}
        aria-hidden
        className="pointer-events-none absolute top-1/2 -translate-y-1/2 text-text-muted"
        style={{ insetInlineStart: '0.75rem' }}
      />
      <input
        ref={ref}
        type="text"
        role="combobox"
        aria-expanded
        aria-autocomplete="list"
        aria-controls={ariaControls}
        aria-activedescendant={ariaActiveDescendant}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onKeyDown={onKeyDown}
        placeholder={placeholder}
        className="w-full bg-transparent py-2.5 ps-9 pe-3 text-sm text-text-primary outline-none placeholder:text-text-muted"
      />
    </div>
  )
})

/** Loading / empty / error rows shown inside a listbox panel. */
export function PanelState({
  loading,
  error,
  isEmpty,
  copy,
  onRetry,
}: {
  loading?: boolean
  error?: string | null
  isEmpty: boolean
  copy: FormsCopy
  onRetry?: () => void
}) {
  if (loading) {
    return (
      <div className="flex items-center justify-center gap-2 px-3 py-6 text-sm text-text-secondary">
        <Spinner /> {copy.loading}
      </div>
    )
  }
  if (error) {
    return (
      <div className="flex flex-col items-center gap-2 px-3 py-6 text-center text-sm text-text-secondary">
        <span className="flex items-center gap-2 text-danger">
          <AlertTriangle size={15} aria-hidden /> {error}
        </span>
        {onRetry && (
          <button
            type="button"
            onClick={onRetry}
            className="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold text-text-secondary hover:text-text-primary"
          >
            <RefreshCw size={13} aria-hidden /> {copy.retry}
          </button>
        )}
      </div>
    )
  }
  if (isEmpty) {
    return <div className="px-3 py-6 text-center text-sm text-text-muted">{copy.noResults}</div>
  }
  return null
}

/** A removable selected-value chip. */
export function Chip({
  children,
  onRemove,
  removeLabel,
  color,
  disabled,
}: {
  children: ReactNode
  onRemove?: () => void
  removeLabel: string
  color?: string | null
  disabled?: boolean
}) {
  return (
    <span className="inline-flex max-w-full items-center gap-1 rounded-full bg-surface-hover px-2 py-0.5 text-xs font-semibold text-text-secondary">
      {color && (
        <span aria-hidden className="inline-block h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: color }} />
      )}
      <span className="truncate">{children}</span>
      {onRemove && !disabled && (
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation()
            onRemove()
          }}
          aria-label={removeLabel}
          className="rounded-full p-0.5 text-text-muted hover:bg-surface-secondary hover:text-text-primary"
        >
          <X size={12} aria-hidden />
        </button>
      )}
    </span>
  )
}

/** Inline clear (×) button for a select trigger. */
export function ClearButton({ onClear, label }: { onClear: () => void; label: string }) {
  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation()
        onClear()
      }}
      aria-label={label}
      className="rounded-full p-0.5 text-text-muted hover:bg-surface-hover hover:text-text-primary"
    >
      <X size={15} aria-hidden />
    </button>
  )
}
