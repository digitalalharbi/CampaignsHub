import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchAddons, updateAddon, type AdminAddon } from './api'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * `/admin/addons` — PRICING-ADDONS-001: the add-ons catalogue, priced and opened for sale from here.
 *
 * A feature pack stays «coming soon» with no price until its running cost is measured; the server refuses to sell anything
 * without a price. Each row shows how many workspaces hold it and how many requests wait (approved in each tenant's drawer).
 */
export function AddonsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const list = useQuery({ queryKey: ['admin', 'addons'], queryFn: fetchAddons })

  return (
    <div data-testid="admin-addons" className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">{ar ? 'الإضافات' : 'Add-ons'}</h1>
        <p className="mt-1 max-w-2xl text-sm text-text-secondary">
          {ar ? 'تسعير الإضافات وفتحها للبيع. لا يُباع شيء بلا سعر، والطلبات تُعتمد من درج كل مستأجر.'
            : 'Price add-ons and open them for sale. Nothing is sold without a price; requests are approved in each tenant’s drawer.'}
        </p>
      </header>
      {list.isPending && <Skeleton className="h-40" />}
      {list.isError && <ErrorState error={list.error} ar={ar} title={ar ? 'تعذّر تحميل الإضافات.' : 'Add-ons could not be loaded.'} onRetry={() => void list.refetch()} />}
      {list.data && (
        <ul className="grid gap-2">
          {list.data.addons.map((a) => <AddonRow key={a.id} a={a} ar={ar} />)}
        </ul>
      )}
    </div>
  )
}

function AddonRow({ a, ar }: { a: AdminAddon; ar: boolean }) {
  const qc = useQueryClient()
  const [price, setPrice] = useState(a.price_monthly ?? '')
  const [readiness, setReadiness] = useState(a.readiness)
  const [reason, setReason] = useState('')
  const save = useMutation({
    mutationFn: () => updateAddon(a.id, { price_monthly: price === '' ? null : Number(price), readiness, reason: reason.trim() }),
    onSuccess: () => { setReason(''); void qc.invalidateQueries({ queryKey: ['admin', 'addons'] }) },
  })
  const changed = price !== (a.price_monthly ?? '') || readiness !== a.readiness

  return (
    <li data-testid={`admin-addon-${a.code}`} className="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-surface px-4 py-3">
      <span className="min-w-0 flex-1">
        <span className="font-semibold text-text-primary">{ar ? a.name_ar : a.name}</span>
        <span className="ms-2 text-[12px] text-text-muted">
          {ar ? `${a.active_workspaces} مساحة · ${a.requests_waiting} طلب بانتظار` : `${a.active_workspaces} workspaces · ${a.requests_waiting} waiting`}
        </span>
      </span>
      <input data-testid={`admin-addon-price-${a.code}`} inputMode="decimal" dir="ltr" value={price} onChange={(e) => setPrice(e.target.value.replace(/[^\d.]/g, ''))}
        aria-label={ar ? 'السعر الشهري' : 'Monthly price'} placeholder={ar ? 'بلا سعر' : 'No price'}
        className="h-9 w-24 rounded-lg border border-border bg-surface px-2 text-sm" />
      <span className="text-[12px] text-text-muted">{a.currency}</span>
      <select data-testid={`admin-addon-readiness-${a.code}`} value={readiness} onChange={(e) => setReadiness(e.target.value as AdminAddon['readiness'])}
        className="h-9 rounded-lg border border-border bg-surface px-2 text-sm">
        <option value="coming_soon">{ar ? 'قريبًا' : 'Coming soon'}</option>
        <option value="available">{ar ? 'متاحة للبيع' : 'On sale'}</option>
      </select>
      <input data-testid={`admin-addon-reason-${a.code}`} value={reason} onChange={(e) => setReason(e.target.value)} placeholder={ar ? 'السبب' : 'Reason'}
        className="h-9 w-44 min-w-0 rounded-lg border border-border bg-surface px-2 text-sm" />
      <Button data-testid={`admin-addon-save-${a.code}`} size="sm" disabled={!changed || reason.trim().length < 5 || save.isPending} onClick={() => save.mutate()}>
        {ar ? 'حفظ' : 'Save'}
      </Button>
      {save.isError && <p className="w-full text-xs font-semibold text-danger">{toApiError(save.error).message}</p>}
    </li>
  )
}
