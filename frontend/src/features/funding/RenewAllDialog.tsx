import { useState } from 'react'
import { RefreshCw } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { providerLabel } from '@/features/campaigns/labels'
import { useUi } from '@/stores/ui'
import { useApplyRenew, useRenewPreview, type RenewPreview } from './fundingApi'
import { currencyMark } from './money'

/**
 * «جدّد كل الميزانيات للشهر القادم» — a PREVIEW first. Nothing is created until the person has seen each amount (editable) and
 * pressed «تأكيد». Budgets are renewed the way the product already renews one (an amount added to the ledger); limits are copied as
 * new limits for next month with the same settings. The old ones are never touched.
 */
export function RenewAllButton() {
  const ar = useUi((s) => s.locale) === 'ar'
  const [open, setOpen] = useState(false)

  return (
    <>
      <Button size="sm" variant="secondary" onClick={() => setOpen(true)} data-testid="renew-all-open">
        <RefreshCw className="h-4 w-4" aria-hidden /> {ar ? 'جدّد كل الميزانيات للشهر القادم' : 'Renew everything for next month'}
      </Button>
      {open && <RenewAllDialog onClose={() => setOpen(false)} />}
    </>
  )
}

function RenewAllDialog({ onClose }: { onClose: () => void }) {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const preview = useRenewPreview(true)

  return (
    <Modal open onClose={onClose} title={ar ? 'تجديد الشهر القادم' : 'Next month’s renewal'}>
      {preview.isLoading ? (
        <p className="text-sm text-text-secondary">…</p>
      ) : preview.isError || !preview.data ? (
        <p role="alert" className="text-sm font-semibold text-danger">{ar ? 'تعذّر تحميل المعاينة.' : 'The preview could not be loaded.'}</p>
      ) : (
        <PreviewForm data={preview.data} ar={ar} onClose={onClose} />
      )}
    </Modal>
  )
}

function PreviewForm({ data, ar, onClose }: { data: RenewPreview; ar: boolean; onClose: () => void }) {
  const locale = useUi((s) => s.locale)
  const apply = useApplyRenew()
  const todo = {
    budgets: data.budgets.filter((b) => !b.already_renewed),
    limits: data.limits.filter((l) => !l.already_renewed),
  }
  const [amounts, setAmounts] = useState<Record<string, string>>(() => ({
    ...Object.fromEntries(todo.budgets.map((b) => [`b:${b.project_id}`, String(b.amount)])),
    ...Object.fromEntries(todo.limits.map((l) => [`l:${l.id}`, String(l.amount)])),
  }))
  const [result, setResult] = useState<{ budgets: number; limits: number } | null>(null)
  const valid = (k: string) => Number.isFinite(Number(amounts[k])) && Number(amounts[k]) > 0
  const allValid = [...todo.budgets.map((b) => `b:${b.project_id}`), ...todo.limits.map((l) => `l:${l.id}`)].every(valid)
  const count = todo.budgets.length + todo.limits.length

  if (result) {
    return (
      <div className="flex flex-col gap-3" data-testid="renew-all-done">
        <p className="text-sm font-semibold text-success">{ar ? `تم: ${result.budgets} ميزانية و${result.limits} حد` : `Done: ${result.budgets} budgets and ${result.limits} limits`}</p>
        <Button onClick={onClose}>{ar ? 'إغلاق' : 'Close'}</Button>
      </div>
    )
  }

  const row = (k: string, label: string, sub: string, currency: string) => (
    <li key={k} className="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-xl border border-border px-3 py-2" data-testid={`renew-row-${k}`}>
      <span className="flex min-w-0 flex-col leading-tight">
        <span className="truncate text-sm font-bold text-text-primary">{label}</span>
        <span className="truncate text-xs text-text-muted">{sub}</span>
      </span>
      <span className="flex items-center gap-1.5">
        <Input dir="ltr" inputMode="decimal" className="w-28" aria-label={label} data-testid={`renew-amount-${k}`} value={amounts[k] ?? ''} onChange={(e) => setAmounts((a) => ({ ...a, [k]: e.target.value }))} />
        <span className="text-xs text-text-muted">{currencyMark(currency)}</span>
      </span>
    </li>
  )

  return (
    <div className="flex flex-col gap-3" data-testid="renew-all-preview">
      <p className="text-sm text-text-secondary">
        {ar ? `راجع المبالغ. ما يُنشأ شيء قبل ما تضغط تأكيد. الشهر: ${data.month}` : `Check the amounts. Nothing is created until you confirm. Month: ${data.month}`}
      </p>
      {count === 0 && <p className="text-sm text-text-secondary" data-testid="renew-all-empty">{ar ? 'لا شيء يحتاج تجديد.' : 'Nothing to renew.'}</p>}
      {todo.budgets.length > 0 && (
        <section className="flex flex-col gap-1.5">
          <h3 className="text-xs font-bold text-text-muted">{ar ? 'الميزانيات' : 'Budgets'}</h3>
          <ul className="flex flex-col gap-1.5">{todo.budgets.map((b) => row(`b:${b.project_id}`, `${b.client_name} · ${b.project_name}`, ar ? 'تُضاف للميزانية' : 'Added to the budget', b.currency))}</ul>
        </section>
      )}
      {todo.limits.length > 0 && (
        <section className="flex flex-col gap-1.5">
          <h3 className="text-xs font-bold text-text-muted">{ar ? 'حدود الإنفاق' : 'Spend limits'}</h3>
          <ul className="flex flex-col gap-1.5">
            {todo.limits.map((l) => row(`l:${l.id}`, l.scope === 'platform' && l.scope_id ? providerLabel(l.scope_id, locale) : (ar ? 'حد' : 'Limit'), `${l.starts_on} → ${l.ends_on}`, l.currency))}
          </ul>
        </section>
      )}
      {apply.isError && <p role="alert" className="text-sm font-semibold text-danger">{ar ? 'تعذّر التجديد.' : 'Could not renew.'}</p>}
      <div className="flex flex-wrap items-center justify-end gap-2">
        <Button variant="secondary" onClick={onClose}>{ar ? 'إلغاء' : 'Cancel'}</Button>
        <Button
          data-testid="renew-all-confirm"
          disabled={count === 0 || !allValid || apply.isPending}
          onClick={() => apply.mutate(
            {
              budgets: todo.budgets.map((b) => ({ project_id: b.project_id, amount: Number(amounts[`b:${b.project_id}`]) })),
              limits: todo.limits.map((l) => ({ id: l.id, amount: Number(amounts[`l:${l.id}`]) })),
            },
            { onSuccess: (r) => setResult(r) },
          )}
        >
          {ar ? 'تأكيد' : 'Confirm'}
        </Button>
      </div>
    </div>
  )
}
