import { useEffect, useRef, useState } from 'react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Textarea } from '@/components/ui/Textarea'
import { ModeChips, PeriodChoice, PlanEditor, emptyPlan, planPayload, planState, presetOf, type PeriodPreset, type PlanDraftRow } from './BudgetOptions'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { useAdjustBudget, useVoidBudget, type BudgetDetail, type BudgetEdit, type PaymentMode } from './fundingApi'
import { L } from './labels'

/**
 * Edit: the amount, the mode (3 chips), the platform split (مخطّطة only), the ceiling (بالأجل only, behind «+ سقف»), the period (one
 * chip), a note. Only what changed is sent; the server writes ONE adjustment entry, so the old values stay in the history and
 * «تراجع» puts them all back. Nothing here edits a past record.
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
  const statedStart = budget.period_start_stated ? (budget.period_start ?? '') : ''
  const storedPlan = (budget.platform_plan ?? []).map((p) => ({ provider: p.provider, value: String(p.percent) }))
  const [amount, setAmount] = useState('')
  const [start, setStart] = useState('')
  const [end, setEnd] = useState('')
  const [preset, setPreset] = useState<PeriodPreset>('custom')
  const [mode, setMode] = useState<PaymentMode>(budget.payment_mode)
  const [planned, setPlanned] = useState(budget.planned)
  const [planRows, setPlanRows] = useState<PlanDraftRow[]>(storedPlan)
  const [ceilingOpen, setCeilingOpen] = useState(budget.ceiling !== null)
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)

  const wasOpen = useRef(false)
  useEffect(() => {
    const opening = open && !wasOpen.current
    wasOpen.current = open
    if (!opening) return
    setAmount(budget.payment_mode === 'credit' ? (budget.ceiling === null ? '' : String(Math.round(budget.ceiling))) : String(Math.round(budget.approved)))
    setStart(statedStart)
    setEnd(budget.period_end ?? '')
    setPreset(presetOf(statedStart, budget.period_end ?? ''))
    setMode(budget.payment_mode)
    setPlanned(budget.planned)
    setPlanRows(budget.platform_plan?.length ? budget.platform_plan.map((p) => ({ provider: p.provider, value: String(p.percent) })) : emptyPlan())
    setCeilingOpen(budget.ceiling !== null)
    setNote('')
    setError(null)
  }, [open, budget, statedStart])

  const credit = mode === 'credit'
  const amountShown = !credit || ceilingOpen
  const value = amountShown ? Number(amount) : 0
  const body: BudgetEdit = {}
  if (mode !== budget.payment_mode) body.payment_mode = mode
  if (amountShown && Math.round(value * 100) !== Math.round(budget.approved * 100)) body.amount = value
  if (credit && !ceilingOpen && budget.approved > 0) body.amount = 0
  if (start !== statedStart) body.period_start = start || null
  if (end !== (budget.period_end ?? '')) body.period_end = end || null
  const planChanged = JSON.stringify(planPayload(planRows)) !== JSON.stringify(planPayload(storedPlan))
  if (planned && (planChanged || !budget.planned)) body.platform_plan = planPayload(planRows)
  if (!planned && budget.planned) body.platform_plan = null
  if (note.trim() !== '') body.note = note.trim()
  const orderOk = !(start && end && end < start)
  const finalAmount = body.amount ?? budget.approved
  const ready = (credit || finalAmount > 0) && Object.keys(body).length > 0 && orderOk && (!planned || planState(planRows).ok)

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
          <ModeChips mode={mode} planned={planned} onMode={setMode} onPlanned={setPlanned} ar={ar} />
        </div>
        {credit && !ceilingOpen ? (
          <div className="sm:col-span-2">
            <button type="button" onClick={() => setCeilingOpen(true)} data-testid="ceiling-chip" className="rounded-[var(--radius-pill)] border border-border px-3 py-1 text-xs font-bold text-brand-700 hover:border-border-strong">
              {tx(L.plusCeiling)}
            </button>
          </div>
        ) : (
          <div className="sm:col-span-2">
            <Field label={credit ? tx(L.ceiling) : tx(L.approved)} htmlFor="edit-amount" required={!credit}>
              <Input id="edit-amount" inputMode="decimal" dir="ltr" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ''))} data-autofocus />
            </Field>
          </div>
        )}
        {planned && <div className="sm:col-span-2"><PlanEditor rows={planRows} onRows={setPlanRows} ar={ar} /></div>}
        <div className="sm:col-span-2">
          <PeriodChoice preset={preset} start={start} end={end} onPreset={(p, r) => { setPreset(p); if (r) { setStart(r.start); setEnd(r.end) } }} onStart={setStart} onEnd={setEnd} ar={ar} idPrefix="edit-period" />
        </div>
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
