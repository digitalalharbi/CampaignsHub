import { useEffect, useRef, useState } from 'react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Textarea } from '@/components/ui/Textarea'
import { DateField } from '@/components/ui/DateField'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { useAdjustBudget, useVoidBudget, type BudgetDetail, type BudgetEdit } from './fundingApi'
import { L, sar } from './labels'

/**
 * Edit: the new budget amount (riyals), the period, a note. Only what changed is sent; the server writes one
 * adjustment entry, so the old amount stays in the history. Nothing here edits a past record.
 */
export function EditBudgetDialog({ open, onClose, budget, onDone }: {
  open: boolean
  onClose: () => void
  budget: BudgetDetail
  onDone: (entryId: string) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const tx = (v: { ar: string; en: string }) => (ar ? v.ar : v.en)
  const adjust = useAdjustBudget()
  const [amount, setAmount] = useState('')
  const [start, setStart] = useState('')
  const [end, setEnd] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)
  const statedStart = budget.period_start_stated ? (budget.period_start ?? '') : ''

  const wasOpen = useRef(false)
  useEffect(() => {
    const opening = open && !wasOpen.current
    wasOpen.current = open
    if (!opening) return
    setAmount(String(Math.round(budget.approved)))
    setStart(statedStart)
    setEnd(budget.period_end ?? '')
    setNote('')
    setError(null)
  }, [open, budget, statedStart])

  const value = Number(amount)
  const body: BudgetEdit = {}
  if (value > 0 && Math.round(value * 100) !== Math.round(budget.approved * 100)) body.amount = value
  if (start !== statedStart) body.period_start = start || null
  if (end !== (budget.period_end ?? '')) body.period_end = end || null
  if (note.trim() !== '') body.note = note.trim()
  const orderOk = !(start && end && end < start)
  const ready = value > 0 && Object.keys(body).length > 0 && orderOk

  const submit = () => {
    setError(null)
    adjust.mutate({ projectId: budget.project_id, body }, {
      onSuccess: (res) => { onDone(res.entry_id); onClose() },
      onError: (e) => setError(toApiError(e).message),
    })
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`${tx(L.edit)} · ${budget.project_name ?? ''}`}
      footer={<Button onClick={submit} disabled={!ready} loading={adjust.isPending} data-testid="budget-edit-submit">{tx(L.save)}</Button>}
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label={`${tx(L.approved)} (${tx(L.sar)})`} htmlFor="edit-amount" required>
            <Input id="edit-amount" inputMode="decimal" dir="ltr" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ''))} data-autofocus />
            <span className="mt-1 block text-xs text-text-muted">{ar ? 'الحالية' : 'Now'} <bdi dir="ltr" className="tnum">{sar(budget.approved)}</bdi></span>
          </Field>
        </div>
        <Field label={`${tx(L.period)} · ${tx(L.from)}`} htmlFor="edit-start">
          <DateField id="edit-start" value={start} onChange={setStart} placeholder={budget.period_start ?? ''} />
        </Field>
        <Field label={`${tx(L.period)} · ${tx(L.to)}`} htmlFor="edit-end" error={orderOk ? undefined : (ar ? 'النهاية قبل البداية' : 'End is before start')}>
          <DateField id="edit-end" value={end} onChange={setEnd} placeholder={tx(L.open)} />
        </Field>
        <div className="sm:col-span-2">
          <Field label={tx(L.note)} htmlFor="edit-note">
            <Input id="edit-note" value={note} onChange={(e) => setNote(e.target.value)} maxLength={300} />
          </Field>
        </div>
        {error && <p role="alert" className="text-sm font-semibold text-danger sm:col-span-2">{error}</p>}
      </div>
    </Modal>
  )
}

/** Delete: a reason is required; the budget is hidden under «المحذوفة», its history kept, and «تراجع» brings it back. */
export function DeleteBudgetDialog({ open, onClose, budget, onDone }: {
  open: boolean
  onClose: () => void
  budget: BudgetDetail
  onDone: (entryId: string) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const tx = (v: { ar: string; en: string }) => (ar ? v.ar : v.en)
  const del = useVoidBudget()
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const wasOpen = useRef(false)
  useEffect(() => {
    const opening = open && !wasOpen.current
    wasOpen.current = open
    if (opening) { setReason(''); setError(null) }
  }, [open])

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`${tx(L.delTitle)} · ${budget.project_name ?? ''}`}
      footer={
        <Button
          variant="danger"
          disabled={reason.trim().length < 2}
          loading={del.isPending}
          data-testid="budget-delete-submit"
          onClick={() => {
            setError(null)
            del.mutate({ projectId: budget.project_id, reason: reason.trim() }, {
              onSuccess: (res) => { onDone(res.entry_id); onClose() },
              onError: (e) => setError(toApiError(e).message),
            })
          }}
        >
          {tx(L.del)}
        </Button>
      }
    >
      <Field label={tx(L.reason)} htmlFor="delete-reason" required>
        <Textarea id="delete-reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} data-autofocus />
      </Field>
      {error && <p role="alert" className="mt-2 text-sm font-semibold text-danger">{error}</p>}
    </Modal>
  )
}
