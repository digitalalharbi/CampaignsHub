import type { ReactNode } from 'react'

/** Label + optional hint + error wrapper used by all form controls. */
export function Field({
  label,
  htmlFor,
  hint,
  error,
  required,
  children,
}: {
  label?: string
  htmlFor?: string
  hint?: string
  error?: string
  required?: boolean
  children: ReactNode
}) {
  return (
    <div className="block">
      {label && (
        <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-semibold text-text-secondary">
          {label}
          {required && <span className="text-danger"> *</span>}
        </label>
      )}
      {children}
      {error ? (
        <span className="mt-1.5 block text-[13px] text-danger" role="alert">
          {error}
        </span>
      ) : hint ? (
        <span className="mt-1.5 block text-[13px] text-text-muted">{hint}</span>
      ) : null}
    </div>
  )
}

// Professional SaaS control: wide, ~54px tall, 16px text, balanced 11px radius, calm border,
// clear focus. No pill shapes, no in-field ornamentation. Shared by Input/Select/Textarea.
export const controlClass =
  'w-full rounded-[11px] border border-border bg-surface px-4 py-3.5 text-base leading-6 ' +
  'text-text-primary outline-none transition-colors placeholder:text-text-muted ' +
  'focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 ' +
  'disabled:opacity-60 disabled:cursor-not-allowed aria-[invalid=true]:border-danger'
