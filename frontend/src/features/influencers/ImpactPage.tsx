import { useState } from 'react'
import { api, getData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { DataTable, type Column, type TableLabels, type TableState } from '@/components/ui/DataTable'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { ChartCard, RankingBarChart } from '@/features/analytics/charts'
import { money, moneyExact, num, percent } from '@/features/analytics/format'
import { formatFixed } from '@/lib/numerals'
import { useSwrQuery } from '@/lib/query/swr'
import { useUi } from '@/stores/ui'

/**
 * INFLUENCER-IMPACT-001 — each creator's figures with the evidence behind them.
 *
 * Mounted inside the influencers portal tree only, so INFL-OFF-001 withdraws this page with the rest. Clicks are the
 * redirect's own count and prove no sale; orders are the store's, tied by the creator's coupon; a ratio appears only
 * where its formula holds; a figure no source measures is «—» and the page says why.
 */
interface ImpactRow {
  influencer_id: string
  name: string
  handle: string | null
  collaborations: number
  links: number
  codes: number
  clicks: number
  visits: null
  add_to_cart: null
  orders: number | null
  confirmed: number | null
  overlap_orders: number | null
  net_revenue: number | null
  currency: string | null
  revenue_by_currency: Record<string, number>
  cost: number | null
  cost_currency: string | null
  cpa: number | null
  roas: number | null
  roi: number | null
  is_ugc: boolean
}

interface ImpactPayload {
  state: 'empty' | 'ready'
  can_see_costs: boolean
  rows: ImpactRow[]
  total: number
  summary: {
    influencers: number
    clicks: number
    orders: number
    overlap_orders: number
    has_ledger: boolean
    comparison: Array<{ group: string; label_ar: string; label_en: string; orders: number }>
  }
  notes: Record<'clicks' | 'orders' | 'visits' | 'overlap', { ar: string; en: string }>
}

const INITIAL: TableState = { page: 1, pageSize: 10, sort: { key: 'orders', dir: 'desc' }, query: '' }

export function ImpactPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const [table, setTable] = useState<TableState>(INITIAL)
  const today = new Date()
  const [range] = useState(() => ({
    from: new Date(today.getTime() - 29 * 86400000).toISOString().slice(0, 10),
    to: today.toISOString().slice(0, 10),
  }))
  const params = new URLSearchParams({ from: range.from, to: range.to, page: String(table.page), per_page: String(table.pageSize) })
  if (table.sort) { params.set('sort', table.sort.key); params.set('dir', table.sort.dir) }
  if (table.query.trim()) params.set('q', table.query.trim())

  const q = useSwrQuery({
    name: 'creator-impact',
    scope: ['influencers'],
    window: [range.from, range.to, table.page, table.pageSize, table.sort?.key, table.sort?.dir, table.query],
    queryFn: () => getData<ImpactPayload>(`/influencers/impact?${params.toString()}`),
  })

  const labels: Partial<TableLabels> = ar
    ? { search: 'ابحث…', rowsPerPage: 'صفوف في الصفحة', previous: 'السابق', next: 'التالي', total: 'إجمالي', page: 'صفحة', columns: 'الأعمدة', expandRow: 'عرض التفاصيل', collapseRow: 'إخفاء التفاصيل' }
    : {}
  const count = (n: number | null) => <span dir="ltr" className="tnum">{n === null ? '—' : num(n)}</span>
  const columns: Column<ImpactRow>[] = [
    { key: 'name', header: ar ? 'المؤثر' : 'Creator', sortable: true, render: (r) => <span className="block max-w-[12rem] truncate font-semibold" dir="auto" title={r.name}>{r.name}{r.is_ugc && <Badge tone="info">UGC</Badge>}</span> },
    { key: 'clicks', header: ar ? 'النقرات' : 'Clicks', sortable: true, align: 'end', render: (r) => count(r.clicks) },
    { key: 'orders', header: ar ? 'طلبات بالكوبون' : 'Coupon orders', sortable: true, align: 'end', render: (r) => count(r.orders) },
    { key: 'confirmed', header: ar ? 'المؤكدة' : 'Confirmed', sortable: true, align: 'end', hideable: true, render: (r) => count(r.confirmed) },
    { key: 'net_revenue', header: ar ? 'صافي المتجر' : 'Store net', sortable: true, align: 'end', render: (r) => <span dir="ltr" className="tnum">{r.net_revenue === null ? '—' : money(r.net_revenue, r.currency)}</span> },
    { key: 'overlap_orders', header: ar ? 'تداخل مع الإعلانات' : 'Overlap with ads', sortable: true, align: 'end', hideable: true, render: (r) => count(r.overlap_orders) },
    ...(q.data?.can_see_costs ? [
      { key: 'cost', header: ar ? 'التكلفة' : 'Cost', sortable: true, align: 'end', hideable: true, render: (r) => <span dir="ltr" className="tnum">{r.cost === null ? '—' : moneyExact(r.cost, r.cost_currency)}</span> },
      { key: 'cpa', header: 'CPA', sortable: true, align: 'end', hideable: true, render: (r) => <span dir="ltr" className="tnum">{r.cpa === null ? '—' : moneyExact(r.cpa, r.cost_currency)}</span> },
      { key: 'roas', header: 'ROAS', sortable: true, align: 'end', hideable: true, render: (r) => <span dir="ltr" className="tnum">{r.roas === null ? '—' : formatFixed(r.roas, 2)}</span> },
      { key: 'roi', header: 'ROI', sortable: true, align: 'end', hideable: true, defaultHidden: true, render: (r) => <span dir="ltr" className="tnum">{r.roi === null ? '—' : percent(r.roi, 0)}</span> },
    ] as Column<ImpactRow>[] : []),
  ]

  const download = async (format: 'csv' | 'xlsx') => {
    const p = new URLSearchParams(params)
    p.delete('page'); p.delete('per_page'); p.set('format', format)
    const res = await api.get(`/influencers/impact/export?${p.toString()}`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data as Blob)
    const a = document.createElement('a')
    a.href = url; a.download = `creator-impact.${format}`; a.click()
    URL.revokeObjectURL(url)
  }

  if (q.isError) {
    return <QueryFailure error={q.error} ar={ar} testId="impact-failure" onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّرت قراءة أثر المؤثرين' : 'Creator impact could not be read'} />
  }
  const d = q.data
  const note = (k: 'clicks' | 'orders' | 'visits' | 'overlap') => d?.notes[k][ar ? 'ar' : 'en']

  return (
    <div data-testid="creator-impact" className="min-w-0 space-y-4">
      <h1 className="text-xl font-bold text-text-primary">{ar ? 'أثر المؤثرين' : 'Creator impact'}</h1>
      <StatGrid columns="grid-cols-2 lg:grid-cols-4">
        <StatCard testid="impact-creators" tone="brand" label={ar ? 'المؤثرون' : 'Creators'} value={<span dir="ltr">{num(d?.summary.influencers ?? null)}</span>} />
        <StatCard testid="impact-clicks" tone="info" label={ar ? 'النقرات منذ الإصدار' : 'Clicks since issued'} value={<span dir="ltr">{num(d?.summary.clicks ?? null)}</span>} />
        <StatCard testid="impact-orders" tone="success" label={ar ? 'طلبات بكوبون المؤثر' : 'Orders by creator coupon'} value={<span dir="ltr">{d?.summary.has_ledger ? num(d.summary.orders) : '—'}</span>} hint={d && !d.summary.has_ledger ? (ar ? 'لا سجل متجر مرتبط بهذه التعاونات' : 'No store ledger is tied to these collaborations') : undefined} />
        <StatCard testid="impact-overlap" tone="warning" label={ar ? 'تداخل مع الإعلانات' : 'Overlap with ads'} value={<span dir="ltr">{d?.summary.has_ledger ? num(d.summary.overlap_orders) : '—'}</span>} />
      </StatGrid>

      <ChartCard title={ar ? 'الترتيب' : 'Ranking'} subtitle={ar ? 'الترتيب والبحث والتقسيم على الخادم. اضغط رأس العمود للترتيب صعودًا أو هبوطًا.' : 'Ranking, search and paging run on the server. Press a column head to rank up or down.'}>
        <div className="min-w-0 space-y-3">
          <div className="flex flex-wrap gap-2">
            <button type="button" onClick={() => void download('csv')} data-testid="impact-export-csv" className="rounded-[8px] border border-border px-2.5 py-1.5 text-xs font-semibold">CSV</button>
            <button type="button" onClick={() => void download('xlsx')} data-testid="impact-export-xlsx" className="rounded-[8px] border border-border px-2.5 py-1.5 text-xs font-semibold">XLSX</button>
          </div>
          <DataTable<ImpactRow>
            columns={columns} rows={d?.rows ?? []} loading={q.isLoading} rowKey={(r) => r.influencer_id} labels={labels}
            emptyTitle={ar ? 'لا مؤثرين في نطاقك' : 'No creators in your scope'} columnPicker stateKey="creator-impact"
            expandRow={(r) => (
              <div data-testid="impact-funnel" className="space-y-1">
                <p dir="auto">{ar ? 'المسار:' : 'Path:'} {ar ? 'نقرات' : 'clicks'} <span dir="ltr" className="tnum">{num(r.clicks)}</span> ← {ar ? 'زيارات' : 'visits'} — ← {ar ? 'إضافة للسلة' : 'add to cart'} — ← {ar ? 'طلبات' : 'orders'} <span dir="ltr" className="tnum">{r.orders === null ? '—' : num(r.orders)}</span> ← {ar ? 'مؤكدة' : 'confirmed'} <span dir="ltr" className="tnum">{r.confirmed === null ? '—' : num(r.confirmed)}</span></p>
                <p>{ar ? `${num(r.links)} رابط · ${num(r.codes)} كود · ${num(r.collaborations)} تعاون` : `${num(r.links)} link(s) · ${num(r.codes)} code(s) · ${num(r.collaborations)} collaboration(s)`}</p>
                {Object.keys(r.revenue_by_currency).length > 1 && <p dir="ltr">{Object.entries(r.revenue_by_currency).map(([c, v]) => moneyExact(v, c)).join(' · ')}</p>}
              </div>
            )}
            server={{ total: d?.total ?? 0, state: table, onChange: setTable }} maxHeight="32rem"
          />
        </div>
      </ChartCard>

      {d && d.summary.comparison.length > 0 && (
        <ChartCard title={ar ? 'المؤثرون وUGC والمنصات' : 'Creators, UGC and platforms'} subtitle={ar ? 'تقسيم لطلبات المتجر نفسها حسب الدليل.' : 'One store ledger, split by the evidence that ties each order.'}>
          <div data-testid="impact-comparison" className="min-w-0">
            <RankingBarChart horizontal height={200} data={d.summary.comparison.map((c) => ({ label: ar ? c.label_ar : c.label_en, orders: c.orders }))} bars={[{ key: 'orders', name: ar ? 'طلبات المتجر' : 'Store orders', kind: 'num' }]} />
          </div>
        </ChartCard>
      )}

      {d && (
        <ul data-testid="impact-notes" className="space-y-1 rounded-xl bg-surface-hover px-4 py-3 text-xs text-text-secondary">
          <li>{note('clicks')}</li>
          <li>{note('orders')}</li>
          <li>{note('visits')}</li>
          <li>{note('overlap')}</li>
        </ul>
      )}
    </div>
  )
}
