import { useEffect, useId, useState } from 'react'
import { Lock, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Textarea } from '@/components/ui/Textarea'
import { Field } from '@/components/ui/Field'
import { Switch } from '@/components/ui/Switch'
import { useUi } from '@/stores/ui'
import { toApiError } from '@/lib/api/client'
import { TAX_COPY } from './taxonomyCopy'
import type { OptionPayload, TaxonomyOption } from './taxonomyApi'

export interface OptionEditDrawerProps {
  open: boolean
  option: TaxonomyOption | null
  onClose: () => void
  /** Injected write — the parent performs the PATCH and resolves with the updated row. */
  onSubmit: (id: string, patch: Partial<OptionPayload>) => Promise<TaxonomyOption>
  onSaved: (option: TaxonomyOption) => void
}

/**
 * Edit slide-over for an existing option. System options are protected: the immutable `key` is
 * always locked, and a notice makes clear only label/description/color/icon/status are editable
 * (the backend enforces the same). Visually mirrors the form library's OptionDrawer.
 */
export function OptionEditDrawer({ open, option, onClose, onSubmit, onSaved }: OptionEditDrawerProps) {
  const locale = useUi((s) => s.locale)
  const c = TAX_COPY[locale]
  const baseId = useId()

  const [labelAr, setLabelAr] = useState('')
  const [labelEn, setLabelEn] = useState('')
  const [description, setDescription] = useState('')
  const [color, setColor] = useState('#0d8a6f')
  const [icon, setIcon] = useState('')
  const [active, setActive] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    if (!open || !option) return
    setLabelAr(option.label_ar ?? '')
    setLabelEn(option.label_en ?? '')
    setDescription(option.description ?? '')
    setColor(option.color ?? '#0d8a6f')
    setIcon(option.icon ?? '')
    setActive(option.is_active)
    setError(null)
    setSubmitting(false)
  }, [open, option])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open || !option) return null

  const submit = async () => {
    if (!labelAr.trim() && !labelEn.trim()) {
      setError(c.required)
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const updated = await onSubmit(option.id, {
        label_ar: labelAr.trim() || labelEn.trim(),
        label_en: labelEn.trim() || labelAr.trim(),
        description: description.trim() || null,
        color: color || null,
        icon: icon.trim() || null,
        is_active: active,
      })
      onSaved(updated)
      onClose()
    } catch (e) {
      setError(toApiError(e).message)
      setSubmitting(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-[60] flex bg-black/40"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose()
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label={c.editTitle}
        className="ms-auto flex h-full w-full max-w-[420px] flex-col overflow-y-auto border-s border-border bg-surface p-5 shadow-[var(--shadow-large)]"
      >
        <div className="mb-4 flex items-center justify-between">
          <h3 className="text-base font-bold text-text-primary">{c.editTitle}</h3>
          <button
            type="button"
            onClick={onClose}
            aria-label={c.cancel}
            className="rounded-[9px] p-1.5 text-text-muted hover:bg-surface-hover"
          >
            <X size={17} aria-hidden />
          </button>
        </div>

        {option.is_system && (
          <p className="mb-3 flex items-center gap-2 rounded-lg bg-surface-hover px-3 py-2 text-[12px] text-text-secondary">
            <Lock size={13} className="shrink-0 text-warning" aria-hidden /> {c.systemNotice}
          </p>
        )}

        <div className="flex flex-col gap-3">
          {/* Key is backend-owned and immutable — always disabled (locked for system options). */}
          <Field label={c.key} htmlFor={`${baseId}-key`} hint={c.keyLocked}>
            <Input id={`${baseId}-key`} value={option.key} disabled readOnly dir="ltr" />
          </Field>
          <Field label={c.labelAr} htmlFor={`${baseId}-ar`}>
            <Input id={`${baseId}-ar`} value={labelAr} onChange={(e) => setLabelAr(e.target.value)} dir="rtl" data-autofocus />
          </Field>
          <Field label={c.labelEn} htmlFor={`${baseId}-en`}>
            <Input id={`${baseId}-en`} value={labelEn} onChange={(e) => setLabelEn(e.target.value)} dir="ltr" />
          </Field>
          <Field label={c.description} htmlFor={`${baseId}-desc`}>
            <Textarea id={`${baseId}-desc`} rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
          </Field>

          <div className="flex gap-3">
            <Field label={c.color} htmlFor={`${baseId}-color`}>
              <input
                id={`${baseId}-color`}
                type="color"
                value={color}
                onChange={(e) => setColor(e.target.value)}
                className="h-11 w-16 cursor-pointer rounded-[10px] border border-border bg-surface p-1"
              />
            </Field>
            <Field label={c.icon} htmlFor={`${baseId}-icon`}>
              <Input id={`${baseId}-icon`} value={icon} onChange={(e) => setIcon(e.target.value)} placeholder="★" maxLength={4} />
            </Field>
          </div>

          <div className="pt-1">
            <Switch id={`${baseId}-active`} checked={active} onCheckedChange={setActive} label={c.active} />
          </div>

          {error && (
            <span className="text-[13px] text-danger" role="alert">
              {error}
            </span>
          )}
        </div>

        <div className="mt-5 flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={submitting}>
            {c.cancel}
          </Button>
          <Button onClick={submit} loading={submitting}>
            {submitting ? c.saving : c.save}
          </Button>
        </div>
      </div>
    </div>
  )
}
