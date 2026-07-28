import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { SelectField } from '@/components/forms'
import type { Option } from '@/components/forms'
import { useUi } from '@/stores/ui'
import { toApiError } from '@/lib/api/client'
import { TAX_COPY, taxOptionLabel } from './taxonomyCopy'
import { optionUsage, type TaxonomyOption } from './taxonomyApi'

export type MergeMode = 'merge' | 'reassign'

export interface MergeReassignDialogProps {
  open: boolean
  mode: MergeMode
  /** The option being merged/reassigned away. */
  source: TaxonomyOption | null
  /** Candidate targets (siblings) — the source is excluded by the caller or here. */
  candidates: TaxonomyOption[]
  onClose: () => void
  /** Injected write: merge or reassign source → target id. Resolves on success. */
  onConfirm: (sourceId: string, targetId: string) => Promise<unknown>
}

/**
 * Merge or reassign flow. Surfaces the source option's live usage count first (delete-protection
 * report), then requires an explicit target before confirming. Backend 409s are shown honestly.
 */
export function MergeReassignDialog({ open, mode, source, candidates, onClose, onConfirm }: MergeReassignDialogProps) {
  const locale = useUi((s) => s.locale)
  const c = TAX_COPY[locale]
  const [target, setTarget] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const usageQ = useQuery({
    queryKey: ['taxonomies', 'usage', source?.id],
    queryFn: () => optionUsage(source!.id),
    enabled: open && Boolean(source),
  })

  useEffect(() => {
    if (open) {
      setTarget(null)
      setError(null)
      setSubmitting(false)
    }
  }, [open, source])

  if (!open || !source) return null

  const targets: Option[] = candidates
    .filter((o) => o.id !== source.id)
    .map((o) => ({
      value: o.id,
      label_ar: o.label_ar,
      label_en: o.label_en,
      color: o.color,
      icon: o.icon,
      disabled: !o.is_active,
    }))

  const confirm = async () => {
    if (!target) return
    setSubmitting(true)
    setError(null)
    try {
      await onConfirm(source.id, target)
      onClose()
    } catch (e) {
      setError(toApiError(e).message)
      setSubmitting(false)
    }
  }

  const count = usageQ.data?.usage_count ?? source.usage_count ?? 0

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`${mode === 'merge' ? c.mergeTitle : c.reassignTitle} — ${taxOptionLabel(source, locale)}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={submitting}>
            {c.cancel}
          </Button>
          <Button onClick={confirm} loading={submitting} disabled={!target || targets.length === 0}>
            {submitting ? c.processing : c.confirm}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-sm text-text-secondary">{mode === 'merge' ? c.mergeExplain : c.reassignExplain}</p>

        <div className="flex items-center gap-2 rounded-lg bg-surface-hover px-3 py-2 text-sm">
          <span className="font-semibold text-text-secondary">{c.usageSummary}</span>
          <span className="tnum font-bold text-text-primary">{usageQ.isLoading ? '…' : count}</span>
          <span className="text-text-tertiary">{c.usageCount}</span>
        </div>

        {targets.length === 0 ? (
          <p className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-text-secondary">
            {c.noTargets}
          </p>
        ) : (
          <SelectField
            label={mode === 'merge' ? c.mergeInto : c.reassignInto}
            value={target}
            onChange={setTarget}
            options={targets}
            placeholder={c.pickTarget}
            searchable
          />
        )}

        {error && (
          <span className="text-[13px] text-danger" role="alert">
            {error}
          </span>
        )}
      </div>
    </Modal>
  )
}
