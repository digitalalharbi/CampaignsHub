import { useState } from 'react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { DateField } from '@/components/ui/DateField'
import { useUpdateSpendLimit, type SpendLimitReading } from './spendLimitsApi'

/**
 * «تعديل» on a spend limit — the amount, the period and the warning points, in place. Until now a limit could only be removed
 * and made again, which loses its record. The scope and currency are not editable: they say WHAT the limit is, and a different
 * one is a different limit. The server keeps 100% among the warnings whatever is chosen here.
 */
const THRESHOLDS = [50, 75, 90]

export function EditSpendLimitDialog({ limit, projectId, ar, onClose }: { limit: SpendLimitReading; projectId: string | null; ar: boolean; onClose: () => void }) {
  const update = useUpdateSpendLimit(projectId)
  const [amount, setAmount] = useState(String(limit.amount))
  const [startsOn, setStartsOn] = useState(limit.period.from)
  const [endsOn, setEndsOn] = useState(limit.period.to)
  const [thresholds, setThresholds] = useState<number[]>(limit.thresholds.filter((t) => t < 100))

  const parsed = Number(amount)
  const ready = Number.isFinite(parsed) && parsed > 0 && startsOn !== '' && endsOn !== '' && endsOn >= startsOn
  const tx = (a: string, e: string) => (ar ? a : e)

  const save = () => {
    if (!ready) return
    update.mutate({ id: limit.id, amount: parsed, starts_on: startsOn, ends_on: endsOn, thresholds }, { onSuccess: onClose })
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={tx('تعديل الحد', 'Edit limit')}
      footer={
        <div className="flex flex-wrap items-center justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>{tx('إلغاء', 'Cancel')}</Button>
          <Button data-testid="edit-limit-save" disabled={!ready || update.isPending} onClick={save}>{tx('حفظ', 'Save')}</Button>
        </div>
      }
    >
      <div data-testid="edit-limit-form" className="flex flex-col gap-4">
        <Field label={`${tx('المبلغ', 'Amount')} (${limit.currency})`} htmlFor="edit-limit-amount" required>
          <Input id="edit-limit-amount" data-testid="edit-limit-amount" dir="ltr" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
        </Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={tx('من', 'From')} htmlFor="edit-limit-from" required>
            <DateField id="edit-limit-from" data-testid="edit-limit-from" value={startsOn} onChange={setStartsOn} />
          </Field>
          <Field label={tx('إلى', 'To')} htmlFor="edit-limit-to" required>
            <DateField id="edit-limit-to" data-testid="edit-limit-to" value={endsOn} onChange={setEndsOn} />
          </Field>
        </div>
        <Field label={tx('نبّهني عند', 'Warn me at')} hint={tx('التنبيه عند 75% و90% و100% يصلك دائمًا.', '75%, 90% and 100% are always sent.')}>
          <div className="flex flex-wrap gap-2">
            {THRESHOLDS.map((p) => {
              const on = thresholds.includes(p)

              return (
                <button
                  key={p}
                  type="button"
                  data-testid={`edit-limit-threshold-${p}`}
                  aria-pressed={on}
                  onClick={() => setThresholds((cur) => (on ? cur.filter((x) => x !== p) : [...cur, p].sort((a, b) => a - b)))}
                  className={`tnum h-11 rounded-xl border px-4 text-sm font-semibold transition-colors sm:h-9 ${on ? 'border-brand-500 bg-brand-500/10 text-brand-600' : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'}`}
                >
                  {p}%
                </button>
              )
            })}
          </div>
        </Field>
        {update.isError && <p data-testid="edit-limit-error" className="text-sm font-semibold text-danger">{tx('تعذّر حفظ التعديل.', 'The change could not be saved.')}</p>}
      </div>
    </Modal>
  )
}
