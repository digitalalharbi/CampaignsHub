import { useState } from 'react'
import { api, getData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { DataTable, type Column, type TableLabels, type TableState } from '@/components/ui/DataTable'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Select } from '@/components/ui/Select'
import { useSwrQuery } from '@/lib/query/swr'
import { ChartCard } from './charts'
import { money, num, percent } from './format'
import { moneyExact } from './format'
import type { OrderRow, OrdersPage, SourceMethod, SourceRow, SourcesPage } from './storeAnalyticsApi'

/**
 * STORE-ANALYTICS-DEPTH-001 — confirmed orders tied to their source by the evidence the order holds.
 *
 * Every row names its method. An order with no evidence reads «غير منسوب» with no source, and two signals that
 * disagree read «متعارض»: neither is resolved here. GA4's columns are GA4's, labelled and never added to the
 * store's. Paging, sort and search run on the server, inside this project.
 */
export const METHOD_LABEL: Record<SourceMethod, { ar: string; en: string }> = {
  utm_campaign: { ar: 'حملة UTM مطابقة', en: 'Matching UTM campaign' },
  click_id: { ar: 'معرّف نقرة المنصة', en: 'Platform click id' },
  utm_source: { ar: 'مصدر UTM', en: 'UTM source' },
  influencer_coupon: { ar: 'كوبون مؤثر', en: 'Creator coupon' },
  coupon: { ar: 'كوبون خصم', en: 'Discount coupon' },
  referral: { ar: 'موقع محيل', en: 'Referring site' },
  conflict: { ar: 'أدلة متعارضة', en: 'Conflicting evidence' },
  unattributed: { ar: 'غير منسوب', en: 'Unattributed' },
}

const GROUPS: Array<{ id: string; ar: string; en: string }> = [
  { id: 'source_medium', ar: 'المصدر / الوسيط', en: 'Source / medium' },
  { id: 'campaign', ar: 'utm_campaign', en: 'utm_campaign' },
  { id: 'content', ar: 'utm_content', en: 'utm_content' },
  { id: 'term', ar: 'utm_term', en: 'utm_term' },
  { id: 'landing', ar: 'صفحة الهبوط', en: 'Landing page' },
  { id: 'coupon', ar: 'الكوبون', en: 'Coupon' },
  { id: 'method', ar: 'طريقة الربط', en: 'Tie method' },
]

const INITIAL_SOURCES: TableState = { page: 1, pageSize: 10, sort: { key: 'orders', dir: 'desc' }, query: '' }
const INITIAL_ORDERS: TableState = { page: 1, pageSize: 10, sort: { key: 'placed_at', dir: 'desc' }, query: '' }

const tableLabels = (ar: boolean): Partial<TableLabels> => ar
  ? { search: 'ابحث…', rowsPerPage: 'صفوف في الصفحة', previous: 'السابق', next: 'التالي', total: 'إجمالي', showFull: 'عرض القيمة كاملة', hideFull: 'طيّ القيمة', page: 'صفحة', columns: 'الأعمدة', expandRow: 'عرض التفاصيل', collapseRow: 'إخفاء التفاصيل' }
  : {}

async function download(path: string, params: URLSearchParams, name: string): Promise<void> {
  const res = await api.get(`${path}?${params.toString()}`, { responseType: 'blob' })
  const url = URL.createObjectURL(res.data as Blob)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  a.click()
  URL.revokeObjectURL(url)
}

function Exports({ prefix, onDownload }: { prefix: string; onDownload: (format: 'csv' | 'xlsx') => void }) {
  return (
    <>
      <button type="button" onClick={() => onDownload('csv')} data-testid={`${prefix}-export-csv`} className="rounded-[8px] border border-border px-2.5 py-1.5 text-xs font-semibold">CSV</button>
      <button type="button" onClick={() => onDownload('xlsx')} data-testid={`${prefix}-export-xlsx`} className="rounded-[8px] border border-border px-2.5 py-1.5 text-xs font-semibold">XLSX</button>
    </>
  )
}

const sourceName = (r: SourceRow, ar: boolean): string => {
  if (r.kind === 'unattributed') return METHOD_LABEL.unattributed[ar ? 'ar' : 'en']
  if (r.kind === 'conflict') return METHOD_LABEL.conflict[ar ? 'ar' : 'en']
  if (r.kind === 'not_tagged') return ar ? 'بلا قيمة في الطلب' : 'Not stated on the order'
  if (r.kind === 'method') return METHOD_LABEL[r.dimension_1 as SourceMethod]?.[ar ? 'ar' : 'en'] ?? r.dimension_1
  return r.dimension_2 ? `${r.dimension_1} / ${r.dimension_2}` : r.dimension_1
}

function MethodBadges({ methods, ar }: { methods: SourceRow['methods']; ar: boolean }) {
  const entries = Object.entries(methods) as Array<[SourceMethod, number]>
  if (entries.length === 0) return <span className="text-text-muted">{ar ? 'لا طلبات' : 'No orders'}</span>
  return (
    <span data-testid="source-methods" className="flex flex-wrap gap-1">
      {entries.map(([method, count]) => (
        <Badge key={method} tone={method === 'unattributed' || method === 'conflict' ? 'warning' : 'neutral'}>{METHOD_LABEL[method]?.[ar ? 'ar' : 'en'] ?? method} <span dir="ltr" className="tnum">{num(count)}</span></Badge>
      ))}
    </span>
  )
}

export function OrderSourcesPanel({ projectId, range, ar }: { projectId: string; range: { from: string; to: string }; ar: boolean }) {
  const [view, setView] = useState<'sources' | 'orders'>('sources')
  return (
    <ChartCard
      title={ar ? 'الطلبات ومصادرها بدليل' : 'Orders and their sources, by evidence'}
      subtitle={ar ? 'يُربط الطلب بمصدره بدليل في بياناته فقط (UTM أو معرّف نقرة أو كوبون أو إحالة)، وإلا فهو «غير منسوب».' : 'An order ties to a source only by evidence in its data (UTM, click id, coupon or referral). Otherwise it reads «unattributed».'}
    >
      <div data-testid="order-sources" className="min-w-0 space-y-3">
        <div role="tablist" className="flex flex-wrap gap-1 text-xs font-semibold">
          {(['sources', 'orders'] as const).map((v) => (
            <button key={v} type="button" role="tab" aria-selected={view === v} data-testid={`order-sources-tab-${v}`} onClick={() => setView(v)}
              className={`rounded-[8px] border px-2.5 py-1.5 ${view === v ? 'border-brand-500 bg-brand-500/10 text-text-primary' : 'border-border text-text-secondary'}`}>
              {v === 'sources' ? (ar ? 'حسب المصدر' : 'By source') : (ar ? 'طلب بطلب' : 'Order by order')}
            </button>
          ))}
        </div>
        {view === 'sources' ? <SourcesTable projectId={projectId} range={range} ar={ar} /> : <OrdersTable projectId={projectId} range={range} ar={ar} />}
      </div>
    </ChartCard>
  )
}

function SourcesTable({ projectId, range, ar }: { projectId: string; range: { from: string; to: string }; ar: boolean }) {
  const [group, setGroup] = useState('source_medium')
  const [table, setTable] = useState<TableState>(INITIAL_SOURCES)
  const params = new URLSearchParams({ group, from: range.from, to: range.to, page: String(table.page), per_page: String(table.pageSize) })
  if (table.sort) { params.set('sort', table.sort.key); params.set('dir', table.sort.dir) }
  if (table.query.trim()) params.set('q', table.query.trim())
  const base = `/projects/${projectId}/commerce/orders/sources`
  const q = useSwrQuery({
    name: 'order-sources',
    scope: [projectId, group],
    window: [range.from, range.to, table.page, table.pageSize, table.sort?.key, table.sort?.dir, table.query],
    queryFn: () => getData<SourcesPage>(`${base}?${params.toString()}`),
  })
  const cur = q.data?.currency ?? ''
  const columns: Column<SourceRow>[] = [
    { key: 'dimension_1', header: ar ? 'المصدر' : 'Source', sortable: true, value: (r) => sourceName(r, ar), render: (r) => <span title={sourceName(r, ar)} dir="auto" className={`block max-w-[14rem] truncate font-semibold ${r.kind === 'value' ? '' : 'text-warning'}`}>{sourceName(r, ar)}</span> },
    { key: 'orders', header: ar ? 'طلبات المتجر' : 'Store orders', sortable: true, align: 'end', render: (r) => <span dir="ltr" className="tnum">{num(r.orders)}</span> },
    { key: 'confirmed', header: ar ? 'المؤكدة' : 'Confirmed', sortable: true, align: 'end', hideable: true, render: (r) => <span dir="ltr" className="tnum">{num(r.confirmed)}</span> },
    { key: 'net_revenue', header: ar ? 'صافي المتجر' : 'Store net', sortable: true, align: 'end', render: (r) => <span dir="ltr" className="tnum">{money(r.net_revenue, cur)}</span> },
    { key: 'share', header: ar ? 'الحصة' : 'Share', sortable: true, align: 'end', hideable: true, render: (r) => <span dir="ltr" className="tnum">{percent(r.share, 1)}</span> },
    { key: 'ga4_sessions', header: ar ? 'جلسات الموقع' : 'Website sessions', sortable: true, align: 'end', hideable: true, render: (r) => <span dir="ltr" className="tnum">{r.ga4_sessions === null ? '—' : num(r.ga4_sessions)}</span> },
    { key: 'confirmed_per_session', header: ar ? 'مؤكد لكل جلسة' : 'Confirmed per session', sortable: true, align: 'end', hideable: true, render: (r) => <span dir="ltr" className="tnum">{r.confirmed_per_session === null ? '—' : percent(r.confirmed_per_session, 2)}</span> },
    { key: 'ga4_add_to_carts', header: ar ? 'إضافات الموقع للسلة' : 'Website add to carts', sortable: true, align: 'end', hideable: true, defaultHidden: true, render: (r) => <span dir="ltr" className="tnum">{r.ga4_add_to_carts === null ? '—' : num(r.ga4_add_to_carts)}</span> },
    { key: 'ga4_revenue', header: ar ? 'إيراد الموقع' : 'Website revenue', sortable: true, align: 'end', hideable: true, defaultHidden: true, render: (r) => <span dir="ltr" className="tnum">{r.ga4_revenue === null ? '—' : money(r.ga4_revenue, r.ga4_currency ?? '')}</span> },
  ]
  const onDownload = (format: 'csv' | 'xlsx') => {
    const p = new URLSearchParams(params)
    p.delete('page'); p.delete('per_page'); p.set('format', format)
    void download(`${base}/export`, p, `order-sources-${group}.${format}`)
  }
  const t = q.data?.totals
  return (
    <div className="min-w-0 space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <Select aria-label={ar ? 'التقسيم' : 'Group by'} value={group} onChange={(e) => { setGroup(e.target.value); setTable(INITIAL_SOURCES) }}
          options={GROUPS.map((g) => ({ value: g.id, label: ar ? g.ar : g.en }))} className="max-w-full sm:max-w-xs" />
        <Exports prefix="order-sources" onDownload={onDownload} />
      </div>
      {q.isError ? (
        <QueryFailure error={q.error} ar={ar} testId="order-sources-failure" onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّرت قراءة مصادر الطلبات' : 'Order sources could not be read'} />
      ) : q.data?.state === 'no_store' ? (
        <p data-testid="order-sources-no-store" className="text-sm text-text-muted">{ar ? 'لا يوجد متجر مربوط بهذا المشروع.' : 'No store is connected to this project.'}</p>
      ) : (
        <>
          <DataTable<SourceRow>
            columns={columns} rows={q.data?.rows ?? []} loading={q.isLoading}
            rowKey={(r) => `${r.kind}\u001f${r.dimension_1}\u001f${r.dimension_2 ?? ''}`}
            labels={tableLabels(ar)} emptyTitle={ar ? 'لا مصادر مطابقة' : 'No matching sources'} columnPicker stateKey={`order-sources:${projectId}`}
            expandRow={(r) => (
              <div className="space-y-1.5">
                <MethodBadges methods={r.methods} ar={ar} />
                {r.money_withheld_orders > 0 && <p className="text-warning">{ar ? `${num(r.money_withheld_orders)} طلبًا بلا مبلغ محوَّل.` : `${num(r.money_withheld_orders)} order(s) with no converted amount.`}</p>}
                {r.ga4_purchases !== null && <p>{ar ? `قاس الموقع ${num(r.ga4_purchases)} عملية شراء لهذا المصدر بنموذجه هو.` : `Website measured ${num(r.ga4_purchases)} purchase(s) for this source, under its own model.`}</p>}
              </div>
            )}
            server={{ total: q.data?.total ?? 0, state: table, onChange: setTable }} maxHeight="32rem"
          />
          {t && (
            <p data-testid="order-sources-footer" className="text-[11px] text-text-muted">
              {ar
                ? `${num(t.orders)} طلبًا حيًا. استُبعد ${num(t.cancelled_excluded)} طلبًا ملغى. ${q.data?.ga4_available ? 'أعمدة الموقع قياس مستقل ولا تُجمع مع المتجر.' : 'لا خاصية الموقع لهذا التقسيم، فأعمدتها فارغة وليست صفرًا.'}`
                : `${num(t.orders)} live orders. ${num(t.cancelled_excluded)} cancelled excluded. ${q.data?.ga4_available ? 'Website columns are a separate measurement, never added to the store.' : 'No Website data for this grouping, so its columns are empty, not zero.'}`}
              {(t.money_withheld_orders ?? 0) > 0 && <span className="block text-warning">{ar ? `${num(t.money_withheld_orders)} طلبًا بعملة (${t.money_withheld_currencies.join('، ')}) بلا سعر صرف موثوق: عُدّت ولم تدخل مبالغها.` : `${num(t.money_withheld_orders)} order(s) in ${t.money_withheld_currencies.join(', ')} have no trustworthy rate: counted, their amounts left out.`}</span>}
            </p>
          )}
        </>
      )}
    </div>
  )
}

function OrdersTable({ projectId, range, ar }: { projectId: string; range: { from: string; to: string }; ar: boolean }) {
  const [method, setMethod] = useState('')
  const [table, setTable] = useState<TableState>(INITIAL_ORDERS)
  const params = new URLSearchParams({ from: range.from, to: range.to, page: String(table.page), per_page: String(table.pageSize) })
  if (table.sort) { params.set('sort', table.sort.key); params.set('dir', table.sort.dir) }
  if (table.query.trim()) params.set('q', table.query.trim())
  if (method) params.set('method', method)
  const base = `/projects/${projectId}/commerce/orders`
  const q = useSwrQuery({
    name: 'store-orders',
    scope: [projectId],
    window: [range.from, range.to, table.page, table.pageSize, table.sort?.key, table.sort?.dir, table.query, method],
    queryFn: () => getData<OrdersPage>(`${base}?${params.toString()}`),
  })
  const cur = q.data?.currency ?? ''
  const columns: Column<OrderRow>[] = [
    { key: 'reference', header: ar ? 'الطلب' : 'Order', render: (r) => <span dir="ltr" className="font-semibold">{r.reference ?? '—'}</span> },
    { key: 'placed_at', header: ar ? 'التاريخ' : 'Date', sortable: true, hideable: true, render: (r) => <span dir="ltr" className="tnum whitespace-nowrap">{r.placed_at?.slice(0, 10) ?? '—'}</span> },
    { key: 'status', header: ar ? 'الحالة' : 'Status', sortable: true, hideable: true, render: (r) => <span>{r.status}{r.confirmed && <Badge tone="success">{ar ? 'مؤكد' : 'Confirmed'}</Badge>}{r.is_demo && <Badge tone="warning">{ar ? 'تجريبي' : 'Demo'}</Badge>}</span> },
    { key: 'original_total', header: ar ? 'المبلغ الأصلي' : 'Original total', sortable: true, align: 'end', render: (r) => <span dir="ltr" className="tnum whitespace-nowrap">{r.original_total === null ? '—' : moneyExact(r.original_total, r.original_currency ?? '')}</span> },
    { key: 'net', header: ar ? 'الصافي' : 'Net', sortable: true, align: 'end', hideable: true, render: (r) => <span dir="ltr" className="tnum whitespace-nowrap">{r.money_withheld ? (ar ? 'محجوب' : 'Withheld') : r.net === null ? '—' : moneyExact(r.net, cur)}</span> },
    { key: 'source', header: ar ? 'المصدر' : 'Source', sortable: true, render: (r) => <span title={r.source ?? undefined} dir="auto" className="block max-w-[10rem] truncate">{r.source ?? '—'}</span> },
    { key: 'method', header: ar ? 'طريقة الربط' : 'Tie method', sortable: true, render: (r) => <Badge tone={r.method === 'unattributed' || r.method === 'conflict' ? 'warning' : 'neutral'}>{r.method_label[ar ? 'ar' : 'en']}</Badge> },
  ]
  const onDownload = (format: 'csv' | 'xlsx') => {
    const p = new URLSearchParams(params)
    p.delete('page'); p.delete('per_page'); p.set('format', format)
    void download(`${base}/export`, p, `store-orders.${format}`)
  }
  return (
    <div className="min-w-0 space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <Select aria-label={ar ? 'طريقة الربط' : 'Tie method'} value={method} onChange={(e) => { setMethod(e.target.value); setTable(INITIAL_ORDERS) }}
          options={[{ value: '', label: ar ? 'كل الطرق' : 'All methods' }, ...(Object.keys(METHOD_LABEL) as SourceMethod[]).map((k) => ({ value: k, label: METHOD_LABEL[k][ar ? 'ar' : 'en'] }))]} className="max-w-full sm:max-w-xs" />
        <Exports prefix="store-orders" onDownload={onDownload} />
      </div>
      {q.isError ? (
        <QueryFailure error={q.error} ar={ar} testId="store-orders-failure" onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّرت قراءة الطلبات' : 'Orders could not be read'} />
      ) : q.data?.state === 'no_store' ? (
        <p data-testid="store-orders-no-store" className="text-sm text-text-muted">{ar ? 'لا يوجد متجر مربوط بهذا المشروع.' : 'No store is connected to this project.'}</p>
      ) : (
        <DataTable<OrderRow>
          columns={columns} rows={q.data?.rows ?? []} loading={q.isLoading} rowKey={(r) => r.id}
          labels={tableLabels(ar)} emptyTitle={ar ? 'لا طلبات مطابقة' : 'No matching orders'} columnPicker stateKey={`store-orders:${projectId}`}
          expandRow={(r) => (
            <dl data-testid="order-evidence" className="grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
              {([
                ['utm_source / utm_medium', r.source && r.medium ? `${r.source} / ${r.medium}` : r.source],
                ['utm_campaign', r.campaign], ['utm_content', r.content], ['utm_term', r.term],
                [ar ? 'الكوبون' : 'Coupon', r.coupon], [ar ? 'منصة معرّف النقرة' : 'Click-id platform', r.click_id_platform],
                [ar ? 'صفحة الهبوط' : 'Landing page', r.landing_path],
              ] as Array<[string, string | null]>).filter(([, v]) => v).map(([k, v]) => (
                <div key={k} className="min-w-0"><dt className="inline text-text-muted">{k}: </dt><dd className="inline break-all" dir="auto">{v}</dd></div>
              ))}
            </dl>
          )}
          server={{ total: q.data?.total ?? 0, state: table, onChange: setTable }} maxHeight="32rem"
        />
      )}
    </div>
  )
}
