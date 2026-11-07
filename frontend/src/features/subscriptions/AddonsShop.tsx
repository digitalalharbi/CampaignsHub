import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Clock, PackagePlus } from 'lucide-react'
import { getAddons, requestAddon, type ShopAddon } from './api'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'

/**
 * PRICING-ADDONS-001 — a compact add-ons shop beside the plan.
 *
 * Each card states what it adds and its monthly price (VAT-exclusive). An add-on that is not ready says «قريبًا» and has no price
 * and no button: nothing unready is offered. Requesting records the request only — nothing is charged and nothing is activated
 * until it is confirmed, and the card says so.
 */
export function AddonsShop({ ar, canManage }: { ar: boolean; canManage: boolean }) {
  const qc = useQueryClient()
  const shop = useQuery({ queryKey: ['subscriptions', 'addons'], queryFn: getAddons })
  const ask = useMutation({
    mutationFn: (code: string) => requestAddon(code),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['subscriptions', 'addons'] }),
  })

  if (!shop.data) return null

  return (
    <section data-testid="addons-shop" className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="flex items-center gap-2 font-heading text-base font-bold text-text-primary">
        <PackagePlus size={16} className="text-brand-600" aria-hidden /> {ar ? 'الإضافات' : 'Add-ons'}
      </h2>
      <p className="mt-1 text-[12px] text-text-muted">
        {ar ? 'الأسعار شهرية ولا تشمل ضريبة القيمة المضافة. الطلب لا يخصم شيئًا ولا يفعّل الإضافة قبل تأكيدها.'
          : 'Monthly prices, VAT excluded. A request charges nothing and activates nothing until it is confirmed.'}
      </p>
      {ask.isError && <p className="mt-2 text-xs font-semibold text-danger">{toApiError(ask.error).message}</p>}
      <ul className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {shop.data.addons.map((a) => <AddonCard key={a.code} a={a} ar={ar} canManage={canManage} pending={ask.isPending} onRequest={() => ask.mutate(a.code)} />)}
      </ul>
    </section>
  )
}

function AddonCard({ a, ar, canManage, pending, onRequest }: { a: ShopAddon; ar: boolean; canManage: boolean; pending: boolean; onRequest: () => void }) {
  return (
    <li data-testid={`addon-${a.code}`} data-readiness={a.readiness} className="flex min-w-0 flex-col gap-2 rounded-xl border border-border p-4">
      <div className="flex items-start justify-between gap-2">
        <span className="font-semibold text-text-primary">{ar ? a.name_ar : a.name}</span>
        {a.active_quantity > 0 && (
          <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-[var(--positive-background)] px-2 py-0.5 text-[11px] font-semibold text-[var(--positive-foreground)]">
            <CheckCircle2 size={11} aria-hidden /> {ar ? `مفعّلة ×${a.active_quantity}` : `Active ×${a.active_quantity}`}
          </span>
        )}
      </div>
      <p className="text-[12px] text-text-secondary">{ar ? a.summary_ar : a.summary_en}</p>
      <div className="mt-auto flex items-center justify-between gap-2">
        {a.sold && a.price_monthly !== null
          ? <span className="tnum text-sm font-bold text-text-primary" dir="ltr">{a.price_monthly} {a.currency}<span className="ms-1 text-[11px] font-normal text-text-muted">{ar ? '/ شهريًا' : '/ month'}</span></span>
          : <span className="inline-flex items-center gap-1 text-[12px] font-semibold text-text-muted"><Clock size={12} aria-hidden /> {ar ? 'قريبًا' : 'Coming soon'}</span>}
        {a.sold && canManage && (
          a.requested
            ? <span data-testid={`addon-requested-${a.code}`} className="text-[12px] font-semibold text-info">{ar ? 'قيد المراجعة' : 'Requested'}</span>
            : <Button data-testid={`addon-request-${a.code}`} size="sm" variant="secondary" disabled={pending} onClick={onRequest}>{ar ? 'اطلبها' : 'Request'}</Button>
        )}
      </div>
    </li>
  )
}
