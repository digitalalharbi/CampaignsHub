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
        <label htmlFor={htmlFor} className="mb-1 block text-[12px] font-semibold text-text-secondary">
          {label}
          {required && <span className="text-danger"> *</span>}
        </label>
      )}
      {children}
      {error ? (
        <span className="mt-1 block text-[11px] text-danger" role="alert">
          {error}
        </span>
      ) : hint ? (
        <span className="mt-1 block text-[11px] text-text-muted">{hint}</span>
      ) : null}
    </div>
  )
}

export const controlClass =
  'w-full rounded-[9px] border border-border bg-surface-secondary px-3 py-2.5 text-[13px] ' +
  'text-text-primary outline-none transition-colors placeholder:text-text-muted ' +
  'focus:border-brand-500 focus:bg-surface focus:ring-[3px] focus:ring-brand-500/15 ' +
  'disabled:opacity-60 disabled:cursor-not-allowed aria-[invalid=true]:border-danger'
