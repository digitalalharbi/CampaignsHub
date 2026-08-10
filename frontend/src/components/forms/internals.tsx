import { forwardRef, type ComponentType, type ReactNode } from 'react'
import { AlertTriangle, Loader2, RefreshCw, Search, X } from 'lucide-react'
import * as LucideIcons from 'lucide-react'
import { controlClass } from '@/components/ui/Field'
import { failureKind } from '@/components/ui/QueryFailure'
import { toApiError } from '@/lib/api/client'
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

/**
 * Leading swatch: a colour dot, a resolved icon, or a short glyph — whichever the option carries.
 *
 * The taxonomy stores icons as LUCIDE NAMES ("rocket", "calendar-check"). Printing that string is what
 * put raw words like "calendar-check" on top of the Arabic labels in the service picker, because the
 * 16px box could not contain them. A name is now resolved to its icon; anything that is not a known
 * icon is treated as a glyph and truncated to a single character so it can never overflow again.
 */
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

  if (!option.icon) return null

  const pascal = option.icon.split(/[-_ ]/).filter(Boolean).map((part) => part[0].toUpperCase() + part.slice(1)).join('')
  const Resolved = (LucideIcons as unknown as Record<string, unknown>)[pascal]
  // Lucide icons are forwardRef objects, not plain functions — checking only for `function` silently
  // dropped every icon to the fallback glyph.
  const isComponent =
    typeof Resolved === 'function' ||
    (typeof Resolved === 'object' && Resolved !== null && '$$typeof' in (Resolved as object))

  if (isComponent) {
    const Icon = Resolved as ComponentType<{ size?: number }>
    return (
      <span aria-hidden className="inline-flex h-4 w-4 shrink-0 items-center justify-center text-text-muted">
        <Icon size={14} />
      </span>
    )
  }

  // Not an icon name — render at most one character (an emoji or a single glyph).
  return (
    <span aria-hidden className="inline-flex h-4 w-4 shrink-0 items-center justify-center overflow-hidden text-[13px] leading-none">
      {[...option.icon][0] ?? ''}
    </span>
  )
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

/**
 * AGENCY-PERMS-006 — say WHICH failure a dropdown hit, inline.
 *
 * These selects took a ready-made sentence («تعذّر تحميل الخيارات») and printed it for a refusal, an
 * expired session and a dead server alike — the same defect `QueryFailure` closed on full-page
 * surfaces, still living on inside every taxonomy control. A signed-in user without
 * `clients.manage` opened the classification editor and was told the product was broken, beside a
 * Retry button whose only possible outcome was the same 403.
 *
 * A caller may still pass its own string — that is its sentence and is used verbatim. Anything else
 * is the raw error, and is classified here rather than at ~20 call sites.
 */
function describeFailure(error: unknown, copy: FormsCopy): { text: string; retryable: boolean } | null {
  if (error === null || error === undefined || error === false) return null
  if (typeof error === 'string') return error ? { text: error, retryable: true } : null

  switch (failureKind(error)) {
    case 'permission':
      return { text: copy.errorPermission, retryable: false }
    case 'session':
      return { text: copy.errorSession, retryable: false }
    case 'not_found':
      return { text: copy.errorNotFound, retryable: false }
    default:
      return { text: toApiError(error).message || copy.error, retryable: true }
  }
}

/** Loading / empty / error rows shown inside a listbox panel. */
export function PanelState({
  loading,
  error,
  isEmpty,
  copy,
  onRetry,
}: {
  loading?: boolean
  /** A sentence the caller owns, or the raw error to be classified. */
  error?: unknown
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
  const failure = describeFailure(error, copy)
  if (failure) {
    return (
      <div className="flex flex-col items-center gap-2 px-3 py-6 text-center text-sm text-text-secondary">
        <span className="flex items-center gap-2 text-danger" data-testid="options-failure">
          <AlertTriangle size={15} aria-hidden /> {failure.text}
        </span>
        {onRetry && failure.retryable && (
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
        // role="button" span (not a <button>) so the chip control is valid HTML inside the trigger <button>.
        <span
          role="button"
          tabIndex={0}
          onClick={(e) => {
            e.stopPropagation()
            onRemove()
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
              e.preventDefault()
              e.stopPropagation()
              onRemove()
            }
          }}
          aria-label={removeLabel}
          className="inline-flex cursor-pointer rounded-full p-0.5 text-text-muted hover:bg-surface-secondary hover:text-text-primary"
        >
          <X size={12} aria-hidden />
        </span>
      )}
    </span>
  )
}

/** Inline clear (×) control for a select trigger. role="button" span (valid inside the trigger <button>). */
export function ClearButton({ onClear, label }: { onClear: () => void; label: string }) {
  return (
    <span
      role="button"
      tabIndex={0}
      onClick={(e) => {
        e.stopPropagation()
        onClear()
      }}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          e.stopPropagation()
          onClear()
        }
      }}
      aria-label={label}
      className="inline-flex cursor-pointer rounded-full p-0.5 text-text-muted hover:bg-surface-hover hover:text-text-primary"
    >
      <X size={15} aria-hidden />
    </span>
  )
}
