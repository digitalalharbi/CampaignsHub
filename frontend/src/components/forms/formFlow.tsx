import { AlertCircle } from 'lucide-react'
import type { ReactNode } from 'react'

/**
 * Shared multi-step form UX primitives (T10 forms overhaul): one consistent stepper header, an accessible
 * validation error-summary, and a key/value review list — so every long form (register, onboarding,
 * requests, campaign/report builders, settings) reads the same. RTL-first, theme-aware, keyboard-friendly.
 */

export interface FormStep {
  /** Stable id used for navigation + draft keys. */
  id: string
  /** Localized short label shown in the stepper. */
  label: string
}

/**
 * Stepper header. `current` is the 0-based active index; completed steps are marked and (if `onStepClick`
 * is given) clickable to jump back. Non-interactive by default so a form can gate forward navigation.
 */
export function FormStepper({
  steps,
  current,
  onStepClick,
  className = '',
}: {
  steps: FormStep[]
  current: number
  onStepClick?: (index: number) => void
  className?: string
}) {
  return (
    <ol className={`flex flex-wrap items-center gap-x-2 gap-y-1 ${className}`} aria-label="progress">
      {steps.map((step, i) => {
        const state = i < current ? 'complete' : i === current ? 'current' : 'upcoming'
        const clickable = Boolean(onStepClick) && i <= current
        const badge = (
          <span
            className={
              'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[13px] font-semibold ' +
              (state === 'current'
                ? 'bg-primary text-white'
                : state === 'complete'
                  ? 'bg-primary/15 text-primary'
                  : 'bg-surface-muted text-text-muted')
            }
          >
            {i + 1}
          </span>
        )
        return (
          <li key={step.id} className="flex items-center gap-2">
            {clickable ? (
              <button
                type="button"
                onClick={() => onStepClick?.(i)}
                className="flex items-center gap-2 rounded-md px-1 py-0.5 hover:bg-surface-muted"
                aria-current={state === 'current' ? 'step' : undefined}
              >
                {badge}
                <span className={state === 'current' ? 'text-sm font-semibold text-text' : 'text-sm text-text-secondary'}>
                  {step.label}
                </span>
              </button>
            ) : (
              <span className="flex items-center gap-2 px-1 py-0.5" aria-current={state === 'current' ? 'step' : undefined}>
                {badge}
                <span className={state === 'current' ? 'text-sm font-semibold text-text' : 'text-sm text-text-muted'}>
                  {step.label}
                </span>
              </span>
            )}
            {i < steps.length - 1 && <span className="mx-1 h-px w-5 bg-border" aria-hidden />}
          </li>
        )
      })}
    </ol>
  )
}

export interface FieldError {
  /** Field id to focus/scroll when the summary entry is activated. */
  field: string
  message: string
}

/**
 * Accessible validation summary shown above a form on submit. Announces via role="alert", lists each error,
 * and (when the field id exists in the DOM) focuses it on click. Render nothing when there are no errors.
 */
export function ErrorSummary({ errors, title, className = '' }: { errors: FieldError[]; title: string; className?: string }) {
  if (errors.length === 0) return null
  return (
    <div
      role="alert"
      className={`rounded-lg border border-danger/30 bg-danger/5 p-3 ${className}`}
      data-testid="error-summary"
    >
      <div className="mb-1.5 flex items-center gap-2 text-sm font-semibold text-danger">
        <AlertCircle className="h-4 w-4" aria-hidden />
        {title}
      </div>
      <ul className="list-disc space-y-0.5 ps-6 text-[13px] text-danger">
        {errors.map((e) => (
          <li key={e.field}>
            <button
              type="button"
              className="underline underline-offset-2 hover:no-underline"
              onClick={() => {
                const el = document.getElementById(e.field) as HTMLElement | null
                el?.focus()
                el?.scrollIntoView?.({ block: 'center', behavior: 'smooth' })
              }}
            >
              {e.message}
            </button>
          </li>
        ))}
      </ul>
    </div>
  )
}

export interface ReviewItem {
  label: string
  value: ReactNode
}

/** A read-only key/value summary for a form's final "review & submit" step. Empty values render as «—». */
export function ReviewList({ items, className = '' }: { items: ReviewItem[]; className?: string }) {
  return (
    <dl className={`divide-y divide-border rounded-lg border border-border ${className}`}>
      {items.map((item, i) => (
        <div key={i} className="flex items-start justify-between gap-4 px-3 py-2.5">
          <dt className="text-sm text-text-muted">{item.label}</dt>
          <dd className="text-sm font-medium text-text text-end">
            {item.value === null || item.value === undefined || item.value === '' ? '—' : item.value}
          </dd>
        </div>
      ))}
    </dl>
  )
}
