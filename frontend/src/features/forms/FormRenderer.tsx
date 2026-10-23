import { useState, type CSSProperties } from 'react'
import { cleanAnswers, pick, stepErrors, visibleFields } from './formLogic'
import type { Answers, AnswerValue, FormDefinition, FormField } from './types'

/**
 * FORMS-HUB-001 — draws a form definition: one step at a time, only the fields the logic asks for, the
 * consent control last. Used by the public page and by the builder's preview, so what the team previews is
 * what a visitor meets.
 *
 * It checks before it sends and the server checks again. A hidden field's answer is never sent.
 */
export type FunnelSignal = { type: 'form_start' } | { type: 'step_completed'; step: number } | { type: 'submit_attempt'; step: number }

export function FormRenderer({ definition, ar, onSubmit, onSignal, submitting = false, serverError = null, preview = false }: {
  definition: FormDefinition
  ar: boolean
  onSubmit: (answers: Answers, consent: boolean) => void
  /** FORMS-FUNNEL-EVENTS-001 — what the visitor did, for the page to report; the preview never passes it. */
  onSignal?: (signal: FunnelSignal, consent: boolean) => void
  submitting?: boolean
  serverError?: string | null
  /** The builder's preview: the same page, but «send» does nothing. */
  preview?: boolean
}) {
  const [answers, setAnswers] = useState<Answers>({})
  const [stepIndex, setStepIndex] = useState(0)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [consent, setConsent] = useState(false)
  const [consentError, setConsentError] = useState(false)
  const [started, setStarted] = useState(false)

  const steps = definition.steps
  const step = steps[stepIndex]
  const last = stepIndex === steps.length - 1
  const asked = new Set(visibleFields(definition, answers).map((f) => f.id))
  const accent = definition.style?.accent ?? undefined
  const style = accent ? ({ '--form-accent': accent } as CSSProperties) : undefined

  const set = (id: string, value: AnswerValue) => {
    if (!started) { setStarted(true); onSignal?.({ type: 'form_start' }, consent) }
    setAnswers((a) => ({ ...a, [id]: value }))
    setErrors((e) => { const { [id]: _drop, ...rest } = e; return rest })
  }

  const next = () => {
    const e = stepErrors(definition, stepIndex, answers, ar)
    setErrors(e)
    if (Object.keys(e).length > 0) return
    onSignal?.({ type: 'step_completed', step: stepIndex + 1 }, consent)
    if (!last) { setStepIndex(stepIndex + 1); return }
    if (definition.consent.required && !consent) { setConsentError(true); return }
    if (!preview) onSignal?.({ type: 'submit_attempt', step: stepIndex + 1 }, consent)
    if (!preview) onSubmit(cleanAnswers(definition, answers), consent)
  }

  if (!step) return null

  return (
    <form
      noValidate
      onSubmit={(e) => { e.preventDefault(); next() }}
      className="mx-auto w-full max-w-xl space-y-5"
      style={style}
      data-testid="form-renderer"
    >
      {steps.length > 1 && (
        <div className="space-y-1" data-testid="form-progress">
          <div className="flex items-center justify-between text-xs text-text-secondary">
            <span>{pick(step.title, ar)}</span>
            <span>{ar ? `الخطوة ${stepIndex + 1} من ${steps.length}` : `Step ${stepIndex + 1} of ${steps.length}`}</span>
          </div>
          <div className="h-1.5 overflow-hidden rounded-full bg-border" role="progressbar" aria-valuemin={1} aria-valuemax={steps.length} aria-valuenow={stepIndex + 1}>
            <div className="h-full rounded-full bg-brand-600" style={{ width: `${((stepIndex + 1) / steps.length) * 100}%`, ...(accent ? { backgroundColor: accent } : {}) }} />
          </div>
        </div>
      )}

      {step.fields.filter((f) => asked.has(f.id)).map((f) => (
        <Field key={f.id} field={f} ar={ar} value={answers[f.id]} error={errors[f.id]} onChange={(v) => set(f.id, v)} />
      ))}

      {last && definition.consent.required && (
        <div className="space-y-1">
          <label className="flex items-start gap-2 text-sm text-text-primary">
            <input
              type="checkbox"
              className="mt-1"
              checked={consent}
              onChange={(e) => { setConsent(e.target.checked); setConsentError(false) }}
              aria-invalid={consentError}
              data-testid="form-consent"
            />
            <span>{pick(definition.consent.text, ar)}</span>
          </label>
          {consentError && <p role="alert" className="text-xs text-danger" data-testid="form-consent-error">{ar ? 'نحتاج موافقتك لإرسال النموذج.' : 'We need your consent to send the form.'}</p>}
        </div>
      )}

      {serverError && <p role="alert" className="rounded-lg border border-danger/40 bg-danger/10 p-3 text-sm text-danger" data-testid="form-server-error">{serverError}</p>}

      <div className="flex items-center justify-between gap-3">
        {stepIndex > 0
          ? <button type="button" onClick={() => setStepIndex(stepIndex - 1)} className="rounded-lg border border-border px-4 py-2 text-sm font-semibold text-text-secondary" data-testid="form-back">{ar ? 'السابق' : 'Back'}</button>
          : <span />}
        <button
          type="submit"
          disabled={submitting}
          className="rounded-lg bg-brand-600 px-5 py-2 text-sm font-bold text-white disabled:opacity-60"
          style={accent ? { backgroundColor: accent } : undefined}
          data-testid="form-next"
        >
          {last ? (ar ? 'إرسال' : 'Send') : (ar ? 'التالي' : 'Next')}
        </button>
      </div>
    </form>
  )
}

function Field({ field, ar, value, error, onChange }: { field: FormField; ar: boolean; value: AnswerValue | undefined; error?: string; onChange: (v: AnswerValue) => void }) {
  const id = `f-${field.id}`
  const label = pick(field.label, ar)
  const inputClass = 'w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-text-primary'
  const common = { id, 'aria-invalid': Boolean(error), 'aria-describedby': error ? `${id}-err` : undefined, 'data-testid': `field-${field.id}` } as const
  const text = value === undefined ? '' : String(value)

  let control
  switch (field.type) {
    case 'textarea':
      control = <textarea {...common} rows={4} className={inputClass} value={text} onChange={(e) => onChange(e.target.value)} />
      break
    case 'select':
      control = (
        <select {...common} className={inputClass} value={text} onChange={(e) => onChange(e.target.value)}>
          <option value="">{ar ? 'اختر…' : 'Choose…'}</option>
          {(field.options ?? []).map((o) => <option key={o.value} value={o.value}>{pick(o.label, ar)}</option>)}
        </select>
      )
      break
    case 'radio':
      control = (
        <div role="radiogroup" aria-labelledby={`${id}-label`} className="space-y-1" data-testid={`field-${field.id}`}>
          {(field.options ?? []).map((o) => (
            <label key={o.value} className="flex items-center gap-2 text-sm text-text-primary">
              <input type="radio" name={id} checked={text === o.value} onChange={() => onChange(o.value)} />
              {pick(o.label, ar)}
            </label>
          ))}
        </div>
      )
      break
    case 'checkbox': {
      const chosen = Array.isArray(value) ? value : []
      control = (
        <div role="group" aria-labelledby={`${id}-label`} className="space-y-1" data-testid={`field-${field.id}`}>
          {(field.options ?? []).map((o) => (
            <label key={o.value} className="flex items-center gap-2 text-sm text-text-primary">
              <input type="checkbox" checked={chosen.includes(o.value)} onChange={(e) => onChange(e.target.checked ? [...chosen, o.value] : chosen.filter((x) => x !== o.value))} />
              {pick(o.label, ar)}
            </label>
          ))}
        </div>
      )
      break
    }
    default: {
      const type = field.type === 'email' ? 'email' : field.type === 'phone' ? 'tel' : field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'
      control = <input {...common} type={type} inputMode={field.type === 'number' ? 'decimal' : undefined} autoComplete={field.maps_to === 'name' ? 'name' : field.maps_to === 'email' ? 'email' : field.maps_to === 'phone' ? 'tel' : undefined} className={inputClass} value={text} onChange={(e) => onChange(e.target.value)} />
    }
  }

  return (
    <div className="space-y-1">
      <label id={`${id}-label`} htmlFor={id} className="block text-sm font-semibold text-text-primary">
        {label}{field.required ? <span aria-hidden className="text-danger"> *</span> : null}
      </label>
      {control}
      {error && <p id={`${id}-err`} role="alert" className="text-xs text-danger">{error}</p>}
    </div>
  )
}
