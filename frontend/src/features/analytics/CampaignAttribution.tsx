import { useState } from 'react'
import { ProviderMark } from '@/components/ProviderMark'
import { Num } from '@/components/ui/Num'
import { money, num } from './format'
import type { CampaignAttribution } from './api'

/**
 * ATTRIB-CAMPAIGN-001 — Owner 2026-11-20, the Thunder model: «المنصات فيها تداخل بالإسناد بينما المتجر طلباته أقل».
 *
 * Each campaign as one row: what the platform claimed (orders) beside what the store matched to the same campaign, drawn
 * as two bars on one scale, and a verdict pill — the platform over-claims (watch it on the store's figures), under-claims
 * (trust the store's figures), or agrees. The store's ROAS and profit sit beside it. Drawn only where a store is connected:
 * without a ledger there is no second number and no verdict.
 */
const VERDICT = {
  over: { ar: 'المنصة تبالغ', en: 'Platform over-claims', cls: 'bg-danger/10 text-danger' },
  under: { ar: 'المنصة تقلّل', en: 'Platform under-claims', cls: 'bg-info/10 text-info' },
  match: { ar: 'متطابقة', en: 'Agrees', cls: 'bg-success/10 text-success' },
} as const

export function CampaignAttributionView({ rows, currency, ar }: { rows: CampaignAttribution[] | undefined; currency: string | null; ar: boolean }) {
  const [filter, setFilter] = useState<'all' | 'over' | 'under' | 'match'>('all')
  const withStore = (rows ?? []).filter((r) => r.store_orders !== null)
  if (withStore.length === 0) return null

  const counts = { over: 0, under: 0, match: 0 }
  for (const r of withStore) if (r.verdict) counts[r.verdict]++
  const shown = withStore.filter((r) => filter === 'all' || r.verdict === filter)
  const ceiling = Math.max(1, ...withStore.map((r) => Math.max(r.platform_orders, r.store_orders ?? 0)))
  const cur = currency ?? undefined

  return (
    <section className="min-w-0 border-t border-border pt-4" data-testid="attribution-campaigns">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h4 className="text-sm font-bold text-text-primary">{ar ? 'الحملات: المنصة مقابل المتجر' : 'Campaigns: platform vs store'}</h4>
        <div className="flex flex-wrap gap-1.5" role="group">
          {(['all', 'over', 'under', 'match'] as const).map((k) => (
            <button key={k} type="button" onClick={() => setFilter(k)} data-testid={`attribution-campaigns-filter-${k}`} aria-pressed={filter === k}
              className={`rounded-full px-2.5 py-1 text-xs font-bold ${filter === k ? 'bg-brand-600 text-white' : k === 'all' ? 'bg-surface-secondary text-text-secondary' : VERDICT[k].cls}`}>
              {k === 'all' ? (ar ? 'الكل' : 'All') : (ar ? VERDICT[k].ar : VERDICT[k].en)} <Num>{k === 'all' ? withStore.length : counts[k]}</Num>
            </button>
          ))}
        </div>
      </div>

      <ul className="grid gap-2">
        {shown.map((r) => (
          <li key={r.id} className="grid grid-cols-1 items-center gap-2 rounded-xl border border-border bg-surface p-2.5 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1.6fr)_auto]" data-testid={`attribution-campaign-${r.id}`} data-verdict={r.verdict ?? ''}>
            <span className="flex min-w-0 items-center gap-2">
              {r.provider && <ProviderMark provider={r.provider} size={20} />}
              <span className="truncate text-sm font-semibold text-text-primary" title={r.name ?? ''}>{r.name ?? '—'}</span>
            </span>

            <span className="grid gap-1" aria-hidden>
              <span className="flex items-center gap-2">
                <span className="w-12 shrink-0 text-xs text-text-muted">{ar ? 'المنصة' : 'Platform'}</span>
                <span className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary"><span className="block h-full rounded-full bg-brand-500" style={{ width: `${(r.platform_orders / ceiling) * 100}%` }} /></span>
                <span className="tnum w-12 shrink-0 text-end text-xs font-bold text-text-primary"><Num>{num(r.platform_orders)}</Num></span>
              </span>
              <span className="flex items-center gap-2">
                <span className="w-12 shrink-0 text-xs text-text-muted">{ar ? 'المتجر' : 'Store'}</span>
                <span className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary"><span className="block h-full rounded-full bg-success" style={{ width: `${((r.store_orders ?? 0) / ceiling) * 100}%` }} /></span>
                <span className="tnum w-12 shrink-0 text-end text-xs font-bold text-text-primary"><Num>{num(r.store_orders ?? 0)}</Num></span>
              </span>
            </span>

            <span className="flex flex-wrap items-center gap-1.5 md:justify-end">
              {r.verdict && <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${VERDICT[r.verdict].cls}`}>{ar ? VERDICT[r.verdict].ar : VERDICT[r.verdict].en}</span>}
              {r.store_roas !== null && <span className="tnum rounded-full bg-surface-secondary px-2 py-0.5 text-xs font-bold text-text-primary" title={ar ? 'معدل العائد من المتجر' : 'Store ROAS'}><Num>{`${r.store_roas.toFixed(2)}×`}</Num></span>}
              {r.profit !== null && <span className={`tnum rounded-full px-2 py-0.5 text-xs font-bold ${r.profit >= 0 ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'}`} title={ar ? 'الربح: المبيعات ناقص المرتجعات ناقص الإنفاق' : 'Profit: sales minus refunds minus spend'}><Num>{money(r.profit, cur)}</Num></span>}
              <span className="sr-only">{ar ? `أبلغت المنصة ${r.platform_orders} طلب، والمتجر سجّل ${r.store_orders}.` : `The platform reported ${r.platform_orders} orders; the store recorded ${r.store_orders}.`}</span>
            </span>
          </li>
        ))}
      </ul>
    </section>
  )
}
