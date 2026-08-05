import { useState, type ReactNode } from 'react'
import { useMutation } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, Copy } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { TextInput, TextareaField } from '@/components/ui/form'
import { toApiError } from '@/lib/api/client'

/**
 * LEGAL-002 — the shared shell behind the contact, support and data-request forms.
 *
 * ## Why one component
 *
 * The three differ in their fields and in what comes back, and in nothing else: all three are a
 * public form that must show a real loading state, a real error and a real success — and must never
 * clear the visitor's typing when the server refuses. Written three times, one of them would end up
 * without the disabled-while-sending guard and take the same message twice.
 *
 * ## Why the success state replaces the form
 *
 * A form still sitting there after a successful send invites a second submission, and the sender has
 * no way to tell whether the first one worked. Where a reference comes back it is the whole content
 * of the success state, with a copy button, because a reference the sender cannot keep is one they
 * will have to ask for again.
 */

export interface PublicFormField {
  name: string
  label: string
  type?: 'text' | 'email' | 'tel' | 'textarea' | 'select'
  required?: boolean
  hint?: string
  rows?: number
  options?: { value: string; label: string }[]
}

interface Props<T> {
  fields: PublicFormField[]
  submit: (values: Record<string, string>) => Promise<T>
  /** Rendered instead of the form once it succeeds. */
  renderSuccess: (result: T) => ReactNode
  cta: string
  sending: string
  ar: boolean
  testId: string
}

export function PublicForm<T>({ fields, submit, renderSuccess, cta, sending, ar, testId }: Props<T>) {
  const [values, setValues] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<T | null>(null)

  const mutation = useMutation({
    mutationFn: () => submit(values),
    onSuccess: (r) => {
      setResult(r)
      setError(null)
    },
    // The typed values are deliberately NOT cleared on failure: a visitor who wrote three paragraphs
    // and met a validation error should not have to write them again.
    onError: (e) => setError(toApiError(e).message),
  })

  if (result !== null) {
    return <div data-testid={`${testId}-success`}>{renderSuccess(result)}</div>
  }

  const set = (name: string) => (e: { target: { value: string } }) =>
    setValues((v) => ({ ...v, [name]: e.target.value }))

  return (
    <form
      data-testid={testId}
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        mutation.mutate()
      }}
    >
      {fields.map((f) =>
        f.type === 'textarea' ? (
          <TextareaField
            key={f.name}
            label={f.label}
            hint={f.hint}
            required={f.required}
            rows={f.rows ?? 5}
            value={values[f.name] ?? ''}
            onChange={set(f.name)}
            data-testid={`${testId}-${f.name}`}
          />
        ) : f.type === 'select' ? (
          <div key={f.name}>
            <label className="mb-1.5 block text-sm font-semibold text-text-secondary" htmlFor={`${testId}-${f.name}`}>
              {f.label}
            </label>
            <select
              id={`${testId}-${f.name}`}
              data-testid={`${testId}-${f.name}`}
              value={values[f.name] ?? ''}
              onChange={set(f.name)}
              className="h-11 w-full rounded-xl border border-border bg-surface px-3 text-sm text-text-primary"
            >
              {(f.options ?? []).map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>
        ) : (
          <TextInput
            key={f.name}
            label={f.label}
            hint={f.hint}
            required={f.required}
            type={f.type ?? 'text'}
            value={values[f.name] ?? ''}
            onChange={set(f.name)}
            data-testid={`${testId}-${f.name}`}
          />
        ),
      )}

      {/*
        The honeypot. Hidden from people and from screen readers, and left empty by both; a bot
        filling every field it finds fills this one, and the API refuses the submission.
      */}
      <input
        type="text"
        name="website"
        tabIndex={-1}
        autoComplete="off"
        aria-hidden="true"
        className="hidden"
        value={values.website ?? ''}
        onChange={set('website')}
      />

      {error && (
        <p data-testid={`${testId}-error`} role="alert" className="flex items-start gap-2 rounded-xl border border-border bg-[var(--danger-background)] px-3 py-2 text-sm text-danger">
          <AlertTriangle size={15} className="mt-0.5 shrink-0" /> {error}
        </p>
      )}

      <Button type="submit" disabled={mutation.isPending} data-testid={`${testId}-submit`}>
        {mutation.isPending ? sending : cta}
      </Button>

      <p className="text-[12.5px] text-text-muted">
        {ar
          ? 'تُستخدم بياناتك للرد على هذه الرسالة فقط، وفق سياسة الخصوصية.'
          : 'Your details are used only to answer this message, under the privacy policy.'}
      </p>
    </form>
  )
}

/** The success panel for the two forms that return a reference the sender should keep. */
export function ReferenceSuccess({ reference, title, note, ar }: { reference: string; title: string; note: string; ar: boolean }) {
  const [copied, setCopied] = useState(false)

  return (
    <div className="rounded-2xl border border-border bg-surface p-6 text-center">
      <CheckCircle2 size={28} className="mx-auto text-success" />
      <h3 className="mt-3 font-heading text-lg font-extrabold text-text-primary">{title}</h3>
      <p className="mt-1.5 text-sm text-text-secondary">{note}</p>

      <div className="mt-4 inline-flex items-center gap-2 rounded-xl border border-border bg-surface-secondary px-4 py-2.5">
        <code data-testid="reference-code" className="text-lg font-bold tracking-wider text-text-primary" dir="ltr">{reference}</code>
        <button
          type="button"
          aria-label={ar ? 'نسخ الرقم المرجعي' : 'Copy the reference'}
          onClick={() => {
            void navigator.clipboard?.writeText(reference)
            setCopied(true)
          }}
          className="text-text-muted hover:text-text-primary"
        >
          <Copy size={15} />
        </button>
      </div>
      {copied && <p className="mt-2 text-xs font-semibold text-success">{ar ? 'نُسخ' : 'Copied'}</p>}
    </div>
  )
}
