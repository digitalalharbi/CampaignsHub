import { useEffect, useId, useState } from 'react'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Textarea } from '@/components/ui/Textarea'
import { Field } from '@/components/ui/Field'
import { useUi } from '@/stores/ui'
import { SelectField } from './SelectField'
import { FORMS_COPY, type Option, type OptionDraft } from './types'

export interface OptionDrawerProps {
  open: boolean
  onClose: () => void
  /**
   * Injected create function. The drawer is API-agnostic — the parent performs the real write and
   * resolves with the created Option, which the drawer hands back via `onCreated`.
   */
  onSubmit: (draft: OptionDraft) => Promise<Option>
  onCreated: (option: Option) => void
  title?: string
  /** Prefill the label fields (e.g. from the search query in a creatable select). */
  initialLabel?: string
  /** When provided, a parent picker is shown (hierarchical taxonomies). */
  parents?: Option[]
  /** Hide fields that don't apply to a given taxonomy. */
  showColor?: boolean
  showIcon?: boolean
}

/**
 * A shared slide-over used by the create affordance. Collects label_ar, label_en, description,
 * color, icon, parent — then calls the injected `onSubmit` and returns the created option. It
 * never touches the API itself, which keeps it API-agnostic and easy to test.
 */
export function OptionDrawer({
  open,
  onClose,
  onSubmit,
  onCreated,
  title,
  initialLabel = '',
  parents,
  showColor = true,
  showIcon = true,
}: OptionDrawerProps) {
  const locale = useUi((s) => s.locale)
  const copy = FORMS_COPY[locale]
  const baseId = useId()

  const [labelAr, setLabelAr] = useState('')
  const [labelEn, setLabelEn] = useState('')
  const [description, setDescription] = useState('')
  const [color, setColor] = useState('#0d8a6f')
  const [icon, setIcon] = useState('')
  const [parentValue, setParentValue] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  // Reset on each open; seed the locale-appropriate label from the query.
  useEffect(() => {
    if (!open) return
    setLabelAr(locale === 'ar' ? initialLabel : '')
    setLabelEn(locale === 'en' ? initialLabel : '')
    setDescription('')
    setColor('#0d8a6f')
    setIcon('')
    setParentValue(null)
    setError(null)
    setSubmitting(false)
  }, [open, initialLabel, locale])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null

  const submit = async () => {
    if (!labelAr.trim() && !labelEn.trim()) {
      setError(copy.required)
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const created = await onSubmit({
        label_ar: labelAr.trim() || labelEn.trim(),
        label_en: labelEn.trim() || labelAr.trim(),
        description: description.trim() || undefined,
        color: showColor ? color : undefined,
        icon: showIcon ? icon.trim() || undefined : undefined,
        parentValue,
      })
      onCreated(created)
      onClose()
    } catch (e) {
      setError(e instanceof Error ? e.message : copy.error)
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
      {/* Slide-over anchored to the inline-end (RTL-correct). */}
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title ?? copy.drawerTitle}
        className="ms-auto flex h-full w-full max-w-[420px] flex-col overflow-y-auto border-s border-border bg-surface p-5 shadow-[var(--shadow-large)]"
      >
        <div className="mb-4 flex items-center justify-between">
          <h3 className="text-base font-bold text-text-primary">{title ?? copy.drawerTitle}</h3>
          <button
            type="button"
            onClick={onClose}
            aria-label={copy.cancel}
            className="rounded-[9px] p-1.5 text-text-muted hover:bg-surface-hover"
          >
            <X size={17} aria-hidden />
          </button>
        </div>

        <div className="flex flex-col gap-3">
          <Field label={copy.labelAr} htmlFor={`${baseId}-ar`}>
            <Input
              id={`${baseId}-ar`}
              value={labelAr}
              onChange={(e) => setLabelAr(e.target.value)}
              dir="rtl"
              data-autofocus
            />
          </Field>
          <Field label={copy.labelEn} htmlFor={`${baseId}-en`}>
            <Input id={`${baseId}-en`} value={labelEn} onChange={(e) => setLabelEn(e.target.value)} dir="ltr" />
          </Field>
          <Field label={copy.description} htmlFor={`${baseId}-desc`}>
            <Textarea
              id={`${baseId}-desc`}
              rows={2}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </Field>

          <div className="flex gap-3">
            {showColor && (
              <Field label={copy.color} htmlFor={`${baseId}-color`}>
                <input
                  id={`${baseId}-color`}
                  type="color"
                  value={color}
                  onChange={(e) => setColor(e.target.value)}
                  className="h-11 w-16 cursor-pointer rounded-[10px] border border-border bg-surface p-1"
                />
              </Field>
            )}
            {showIcon && (
              <Field label={copy.icon} htmlFor={`${baseId}-icon`}>
                <Input
                  id={`${baseId}-icon`}
                  value={icon}
                  onChange={(e) => setIcon(e.target.value)}
                  placeholder="★"
                  maxLength={4}
                />
              </Field>
            )}
          </div>

          {parents && parents.length > 0 && (
            <SelectField
              label={copy.parent}
              value={parentValue}
              onChange={setParentValue}
              options={parents}
              placeholder={copy.noParent}
            />
          )}

          {error && (
            <span className="text-[13px] text-danger" role="alert">
              {error}
            </span>
          )}
        </div>

        <div className="mt-5 flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={submitting}>
            {copy.cancel}
          </Button>
          <Button onClick={submit} loading={submitting}>
            {submitting ? copy.saving : copy.save}
          </Button>
        </div>
      </div>
    </div>
  )
}
