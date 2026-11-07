import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchUsageMeters, updateUsageMeter, type AdminUsageMeter } from './api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/** The plans a monthly allowance can be set for — the current catalogue. */
const PLANS = ['solo', 'team', 'agency_2026'] as const

/**
 * `/admin/usage` — PRICING-USAGE-CREDITS-001: what is metered, who pays for it, its actual cost and allowances per plan.
 *
 * A meter funded by the customer's own key is shown but can never charge credits. A platform meter charges only after its actual
 * unit cost is entered and charging is switched on; until then usage is recorded and nothing is taken. Every save needs a reason.
 */
export function UsageMetersPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const list = useQuery({ queryKey: ['admin', 'usage-meters'], queryFn: fetchUsageMeters })

  return (
    <div data-testid="admin-usage" className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">{ar ? 'الاستهلاك والرصيد' : 'Usage and credits'}</h1>
        <p className="mt-1 max-w-2xl text-sm text-text-secondary">
          {ar ? 'ما يُقاس، ومن يدفع تكلفته، وتكلفته الفعلية، والحصة الشهرية لكل باقة. لا يُخصم شيء قبل إدخال التكلفة الفعلية وتفعيل الخصم، واستهلاك مفتاح العميل لا يُخصم أبدًا. أرصدة المستأجرين تُعدَّل من درج كل مستأجر.'
            : 'What is metered, who pays for it, its actual cost and the monthly allowance per plan. Nothing is charged before the actual cost is entered and charging is on, and usage on a customer’s own key is never charged. Workspace balances are adjusted in each tenant’s drawer.'}
        </p>
      </header>
      {list.isPending && <Skeleton className="h-40" />}
      {list.isError && <ErrorState error={list.error} ar={ar} title={ar ? 'تعذّر تحميل العدّادات.' : 'Meters could not be loaded.'} onRetry={() => void list.refetch()} />}
      {list.data && (
        <ul className="grid gap-2">
          {list.data.meters.map((m) => <MeterRow key={m.code} m={m} ar={ar} />)}
        </ul>
      )}
    </div>
  )
}

function MeterRow({ m, ar }: { m: AdminUsageMeter; ar: boolean }) {
  const qc = useQueryClient()
  const ownKey = m.funded_by === 'customer'
  const [cost, setCost] = useState(m.unit_cost ?? '')
  const [charges, setCharges] = useState(m.charges_credits)
  const [included, setIncluded] = useState<Record<string, string>>(() => Object.fromEntries(PLANS.map((p) => [p, m.included_per_plan[p] === undefined ? '' : String(m.included_per_plan[p])])))
  const [reason, setReason] = useState('')
  const save = useMutation({
    mutationFn: () => updateUsageMeter(m.code, {
      ...(ownKey ? {} : { unit_cost: cost === '' ? null : Number(cost), charges_credits: charges }),
      included_per_plan: Object.fromEntries(PLANS.map((p) => [p, included[p] === '' ? null : Number(included[p])])),
      reason: reason.trim(),
    }),
    onSuccess: () => { setReason(''); void qc.invalidateQueries({ queryKey: ['admin', 'usage-meters'] }) },
  })

  return (
    <li data-testid={`admin-meter-${m.code}`} className="grid gap-2 rounded-xl border border-border bg-surface px-4 py-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-semibold text-text-primary">{ar ? m.name_ar : m.name}</span>
        <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${ownKey ? 'bg-info/15 text-info' : 'bg-surface-secondary text-text-secondary'}`}>
          {ownKey ? (ar ? 'على مفتاح العميل — لا يُخصم أبدًا' : "Customer's own key — never charged") : (ar ? 'تدفعه المنصة' : 'Paid by the platform')}
        </span>
        <span data-testid={`admin-meter-state-${m.code}`} className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${m.charges ? 'bg-warning/15 text-warning' : 'bg-surface-secondary text-text-muted'}`}>
          {m.charges ? (ar ? 'يُخصم من الرصيد' : 'Charges credits') : (ar ? 'يُسجَّل فقط' : 'Recorded only')}
        </span>
      </div>
      <div className="flex flex-wrap items-center gap-2 text-sm">
        {!ownKey && (
          <>
            <input data-testid={`admin-meter-cost-${m.code}`} inputMode="decimal" dir="ltr" value={cost} onChange={(e) => setCost(e.target.value.replace(/[^\d.]/g, ''))}
              aria-label={ar ? 'التكلفة الفعلية للوحدة' : 'Actual cost per unit'} placeholder={ar ? 'بلا تكلفة' : 'No cost'}
              className="h-9 w-24 rounded-lg border border-border bg-surface px-2 text-sm" />
            <span className="text-[12px] text-text-muted">{m.currency} / {m.unit}</span>
            <label className="flex items-center gap-1.5 text-[13px] text-text-secondary">
              <input data-testid={`admin-meter-charges-${m.code}`} type="checkbox" checked={charges} onChange={(e) => setCharges(e.target.checked)} />
              {ar ? 'خصم من الرصيد' : 'Charge credits'}
            </label>
          </>
        )}
        {PLANS.map((p) => (
          <label key={p} className="flex items-center gap-1 text-[12px] text-text-muted">
            {p.replace('_2026', '')}
            <input data-testid={`admin-meter-included-${m.code}-${p}`} inputMode="numeric" dir="ltr" value={included[p]}
              onChange={(e) => setIncluded({ ...included, [p]: e.target.value.replace(/\D/g, '') })}
              aria-label={ar ? `الحصة الشهرية — ${p}` : `Monthly allowance — ${p}`} placeholder="—"
              className="h-9 w-20 rounded-lg border border-border bg-surface px-2 text-sm" />
          </label>
        ))}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <input data-testid={`admin-meter-reason-${m.code}`} value={reason} onChange={(e) => setReason(e.target.value)} placeholder={ar ? 'السبب' : 'Reason'}
          className="h-9 min-w-0 flex-1 rounded-lg border border-border bg-surface px-2 text-sm" />
        <Button data-testid={`admin-meter-save-${m.code}`} size="sm" disabled={reason.trim().length < 5 || save.isPending} onClick={() => save.mutate()}>
          {ar ? 'حفظ' : 'Save'}
        </Button>
      </div>
      {save.isError && <p className="text-xs font-semibold text-danger">{toApiError(save.error).message}</p>}
    </li>
  )
}
