import { forwardRef, useId, useState, type InputHTMLAttributes, type ReactNode, type TextareaHTMLAttributes } from 'react'
import { AlertCircle, Eye, EyeOff } from 'lucide-react'
import { controlClass } from './Field'

/**
 * Central form design system — one professional SaaS look for every form (auth, onboarding, requests,
 * campaign/report builders). Wide fields (~54px), 16px text, labels ABOVE, calm borders, clear focus
 * and error states, no pill shapes or in-field ornamentation. Never use placeholders as labels.
 */

export function FormField({
  label, htmlFor, hint, error, required, children,
}: { label: string; htmlFor?: string; hint?: string; error?: string; required?: boolean; children: ReactNode }) {
  return (
    <div>
      <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-semibold text-text-secondary">
        {label}{required && <span className="text-danger"> *</span>}
      </label>
      {children}
      {error ? <span className="mt-1.5 block text-[13px] text-danger" role="alert">{error}</span>
        : hint ? <span className="mt-1.5 block text-[13px] text-text-muted">{hint}</span> : null}
    </div>
  )
}

type BaseInputProps = InputHTMLAttributes<HTMLInputElement> & { label: string; hint?: string; error?: string }

export const TextInput = forwardRef<HTMLInputElement, BaseInputProps>(function TextInput({ label, hint, error, required, id, className, ...props }, ref) {
  const auto = useId()
  const fid = id ?? auto
  return (
    <FormField label={label} htmlFor={fid} hint={hint} error={error} required={required}>
      <input ref={ref} id={fid} aria-invalid={error ? true : undefined} className={`${controlClass} ${className ?? ''}`} {...props} />
    </FormField>
  )
})

export const EmailInput = forwardRef<HTMLInputElement, BaseInputProps>(function EmailInput(props, ref) {
  return <TextInput ref={ref} type="email" autoComplete="username" {...props} />
})

export const PasswordInput = forwardRef<HTMLInputElement, BaseInputProps & { showLabel?: string; hideLabel?: string }>(
  function PasswordInput({ label, hint, error, required, id, className, showLabel = 'Show password', hideLabel = 'Hide password', ...props }, ref) {
    const auto = useId()
    const fid = id ?? auto
    const [show, setShow] = useState(false)
    return (
      <FormField label={label} htmlFor={fid} hint={hint} error={error} required={required}>
        <div className="relative">
          <input
            ref={ref} id={fid} type={show ? 'text' : 'password'} aria-invalid={error ? true : undefined}
            className={`${controlClass} pe-12 ${className ?? ''}`} {...props}
          />
          <button
            type="button" onClick={() => setShow((v) => !v)} aria-label={show ? hideLabel : showLabel} tabIndex={-1}
            className="absolute inset-y-0 end-2.5 my-auto flex h-9 w-9 items-center justify-center rounded-lg text-text-muted hover:bg-surface-hover"
          >
            {show ? <EyeOff size={18} /> : <Eye size={18} />}
          </button>
        </div>
      </FormField>
    )
  },
)

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; hint?: string; error?: string; maxLength?: number; value?: string }

export const TextareaField = forwardRef<HTMLTextAreaElement, TextareaProps>(function TextareaField({ label, hint, error, required, id, className, maxLength, value, ...props }, ref) {
  const auto = useId()
  const fid = id ?? auto
  const count = typeof value === 'string' ? value.length : 0
  return (
    <FormField label={label} htmlFor={fid} hint={hint} error={error} required={required}>
      <textarea
        ref={ref} id={fid} value={value} maxLength={maxLength} aria-invalid={error ? true : undefined}
        className={`${controlClass} min-h-[140px] resize-y ${className ?? ''}`} {...props}
      />
      {maxLength ? <div className="mt-1 text-end text-[12px] tabular-nums text-text-muted">{count}/{maxLength}</div> : null}
    </FormField>
  )
})

export function FormSection({ title, description, children }: { title?: string; description?: string; children: ReactNode }) {
  return (
    <section className="space-y-5">
      {(title || description) && (
        <div>
          {title && <h3 className="text-base font-bold text-text-primary">{title}</h3>}
          {description && <p className="mt-0.5 text-sm text-text-secondary">{description}</p>}
        </div>
      )}
      <div className="space-y-[18px]">{children}</div>
    </section>
  )
}

export function FormActions({ children, align = 'end' }: { children: ReactNode; align?: 'end' | 'between' | 'start' }) {
  const j = align === 'between' ? 'justify-between' : align === 'start' ? 'justify-start' : 'justify-end'
  return <div className={`flex flex-wrap items-center gap-3 ${j}`}>{children}</div>
}

export function FormErrorSummary({ errors }: { errors: Record<string, string[]> | null | undefined }) {
  const items = errors ? Object.values(errors).flat() : []
  if (!items.length) return null
  return (
    <div className="flex gap-2.5 rounded-xl border border-danger/30 bg-[var(--negative-background)] p-3.5" role="alert">
      <AlertCircle size={18} className="mt-0.5 shrink-0 text-danger" />
      <ul className="space-y-0.5 text-sm text-danger">{items.map((e, i) => <li key={i}>{e}</li>)}</ul>
    </div>
  )
}

/** Card-style radio group (used for account-type / module selection in onboarding). */
export function RadioCardGroup<T extends string>({
  value, onChange, options, columns = 2,
}: { value: T | null; onChange: (v: T) => void; options: Array<{ value: T; label: string; description?: string; icon?: ReactNode }>; columns?: 1 | 2 | 3 }) {
  const cols = columns === 1 ? 'sm:grid-cols-1' : columns === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2'
  return (
    <div className={`grid grid-cols-1 gap-3 ${cols}`} role="radiogroup">
      {options.map((o) => {
        const active = value === o.value
        return (
          <button
            key={o.value} type="button" role="radio" aria-checked={active} onClick={() => onChange(o.value)}
            className={`flex items-start gap-3 rounded-xl border p-4 text-start transition-colors ${active ? 'border-brand-600 bg-[var(--brand-background)] ring-1 ring-brand-600' : 'border-border hover:border-border-strong hover:bg-surface-hover'}`}
          >
            {o.icon && <span className={`mt-0.5 ${active ? 'text-brand-600' : 'text-text-muted'}`}>{o.icon}</span>}
            <span className="min-w-0">
              <span className="block text-sm font-bold text-text-primary">{o.label}</span>
              {o.description && <span className="mt-0.5 block text-xs leading-snug text-text-muted">{o.description}</span>}
            </span>
          </button>
        )
      })}
    </div>
  )
}
