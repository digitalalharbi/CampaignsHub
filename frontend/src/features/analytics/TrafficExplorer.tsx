import { Num } from '@/components/ui/Num'
import { useEffect, useState } from 'react'
import { api, getData } from '@/lib/api/client'
import { DataTable, type Column, type TableLabels, type TableState } from '@/components/ui/DataTable'
import { Select } from '@/components/ui/Select'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { useSwrQuery } from '@/lib/query/swr'
import { money, num, percent } from './format'
import { ChartCard } from './charts'

/**
 * TRAFFIC-DRILLDOWN-001 / TABLE-SERVER-PAGING-001 — where visits came from, one page at a time.
 *
 * The server pages, sorts and filters (scope = this project's selected property, in the query); the
 * table only shows the page it was handed. The drill-down is a chain of exact filters over stored
 * breakdowns: channel → source / medium → campaign → landing page. CSV and XLSX download EVERY row
 * in scope for the current view and filter, whatever page is on screen.
 */
interface TrafficRow { dimension_1: string; dimension_2: string | null; sessions: number | null; engaged_sessions: number | null; key_events?: number | null; add_to_carts?: number | null; checkouts?: number | null; purchases: number | null; revenue: number | null; engagement_rate: number | null; conversion_rate: number | null }
interface TrafficPage { state: 'not_connected' | 'not_selected' | 'ready'; rows: TrafficRow[]; total: number; page: number; per_page: number; currency: string | null }

type View = 'drill' | 'traffic_class' | 'referrals' | 'source_medium' | 'first_user_source_medium' | 'utm_content' | 'utm_term' | 'landing_page' | 'campaign_landing'
interface Chain { channel?: string; sourceMedium?: string; campaign?: string }

const VIEWS: Array<{ id: View; ar: string; en: string }> = [
  { id: 'drill', ar: 'مسار التعمّق: القناة ← المصدر ← الحملة ← الصفحة', en: 'Drill-down: channel → source → campaign → page' },
  { id: 'traffic_class', ar: 'مدفوع / عضوي / مباشر', en: 'Paid / organic / direct' },
  { id: 'referrals', ar: 'المواقع المحيلة', en: 'Referrals' },
  { id: 'source_medium', ar: 'المصدر / الوسيط (الجلسة)', en: 'Source / medium (session)' },
  { id: 'first_user_source_medium', ar: 'المصدر / الوسيط (أول مستخدم)', en: 'Source / medium (first user)' },
  { id: 'utm_content', ar: 'utm_content', en: 'utm_content' },
  { id: 'utm_term', ar: 'utm_term', en: 'utm_term' },
  { id: 'landing_page', ar: 'صفحات الهبوط', en: 'Landing pages' },
  { id: 'campaign_landing', ar: 'الحملة ← صفحة الهبوط', en: 'Campaign → landing page' },
]

/** GA4's channel groups folded by the server; the name is translated here. */
const TRAFFIC_CLASS: Record<string, { ar: string; en: string }> = {
  paid: { ar: 'مدفوع', en: 'Paid' },
  organic: { ar: 'عضوي', en: 'Organic' },
  direct: { ar: 'مباشر', en: 'Direct' },
  referral: { ar: 'إحالة', en: 'Referral' },
  other: { ar: 'أخرى', en: 'Other' },
}

/** What the drill chain asks the server for at its current depth. */
export function drillRequest(chain: Chain): { breakdown: string; where_d1?: string; where_d2?: string; depth: 0 | 1 | 2 | 3 } {
  if (chain.campaign) return { breakdown: 'campaign_landing', where_d1: chain.campaign, depth: 3 }
  if (chain.sourceMedium) return { breakdown: 'campaign', where_d2: chain.sourceMedium, depth: 2 }
  if (chain.channel) return { breakdown: 'channel_source', where_d1: chain.channel, depth: 1 }
  return { breakdown: 'default_channel_group', depth: 0 }
}

const INITIAL: TableState = { page: 1, pageSize: 10, sort: { key: 'sessions', dir: 'desc' }, query: '' }

/** State kept for the session so a return to the tab lands where the reader left (per project; storage may be blocked). */
const savedKey = (projectId: string) => `traffic-table:${projectId}`
function loadState(projectId: string): { view: View; chain: Chain; table: TableState } {
  try {
    const raw = window.sessionStorage.getItem(savedKey(projectId))
    if (raw) {
      const v = JSON.parse(raw) as { view: View; chain: Chain; table: TableState }
      if (VIEWS.some((x) => x.id === v.view) && [10, 25, 50].includes(v.table.pageSize)) return v
    }
  } catch { /* unreadable or blocked: start fresh */ }
  return { view: 'drill', chain: {}, table: INITIAL }
}

export function TrafficExplorer({ projectId, range, ar }: { projectId: string; range: { from: string; to: string }; ar: boolean }) {
  const [saved] = useState(() => loadState(projectId))
  const [view, setView] = useState<View>(saved.view)
  const [chain, setChain] = useState<Chain>(saved.chain)
  const [table, setTable] = useState<TableState>(saved.table)
  useEffect(() => {
    try { window.sessionStorage.setItem(savedKey(projectId), JSON.stringify({ view, chain, table })) } catch { /* not remembered */ }
  }, [projectId, view, chain, table])

  const drill = view === 'drill' ? drillRequest(chain) : null
  const breakdown = drill ? drill.breakdown : view
  const params = new URLSearchParams({ breakdown, from: range.from, to: range.to, page: String(table.page), per_page: String(table.pageSize) })
  if (table.sort) { params.set('sort', table.sort.key); params.set('dir', table.sort.dir) }
  if (table.query.trim()) params.set('q', table.query.trim())
  if (drill?.where_d1) params.set('where_d1', drill.where_d1)
  if (drill?.where_d2) params.set('where_d2', drill.where_d2)
  const base = `/projects/${projectId}/measurement/analytics/table`

  const q = useSwrQuery({
    name: 'site-traffic',
    scope: [projectId, breakdown, drill?.where_d1, drill?.where_d2],
    window: [range.from, range.to, table.page, table.pageSize, table.sort?.key, table.sort?.dir, table.query],
    queryFn: () => getData<TrafficPage>(`${base}?${params.toString()}`),
  })

  const reset = (next: Partial<TableState> = {}) => setTable({ ...INITIAL, ...next })
  const go = (c: Chain) => { setChain(c); reset() }

  const labels: Partial<TableLabels> = ar
    ? { search: 'ابحث…', rowsPerPage: 'صفوف في الصفحة', previous: 'السابق', next: 'التالي', total: 'إجمالي', showFull: 'عرض القيمة كاملة', hideFull: 'طيّ القيمة', page: 'صفحة', columns: 'الأعمدة', expandRow: 'عرض التفاصيل', collapseRow: 'إخفاء التفاصيل' }
    : {}
  const cur = q.data?.currency ?? ''
  // The level's name column: the channel and landing views name by dimension_1 except where dimension_2 is the thing drilled to.
  const nameOf = (r: TrafficRow): string => view === 'traffic_class' ? (TRAFFIC_CLASS[r.dimension_1]?.[ar ? 'ar' : 'en'] ?? r.dimension_1) : (drill?.depth === 1 ? (r.dimension_2 ?? r.dimension_1) : drill?.depth === 3 ? (r.dimension_2 ?? r.dimension_1) : r.dimension_1)
  const nameKey = drill?.depth === 1 || drill?.depth === 3 ? 'dimension_2' : 'dimension_1'
  const sub = (r: TrafficRow): string | null => (drill ? null : r.dimension_2)

  const onDrill = (r: TrafficRow) => {
    if (!drill) return
    if (drill.depth === 0) go({ channel: r.dimension_1 })
    else if (drill.depth === 1 && r.dimension_2) go({ ...chain, sourceMedium: r.dimension_2 })
    else if (drill.depth === 2) go({ ...chain, campaign: r.dimension_1 })
  }
  const leaf = drill?.depth === 3

  const columns: Column<TrafficRow>[] = [
    {
      key: nameKey, header: ar ? 'الاسم' : 'Name', sortable: true, align: 'start',
      value: nameOf,
      render: (r) => {
        const text = nameOf(r)
        return drill && !leaf ? (
          <button type="button" onClick={() => onDrill(r)} title={text} data-testid="traffic-drill" dir="auto"
            className="block max-w-[14rem] truncate text-start font-semibold text-brand-600 hover:underline">{text}</button>
        ) : (
          <span title={text} dir="auto" className="block max-w-[14rem] truncate font-semibold">
            {text}{sub(r) ? <span className="block truncate text-[11px] font-normal text-text-muted" dir="ltr">{sub(r)}</span> : null}
          </span>
        )
      },
    },
    { key: 'sessions', header: ar ? 'الجلسات' : 'Sessions', sortable: true, align: 'end', value: (r) => r.sessions ?? -1, render: (r) => <span dir="ltr" className="tnum">{num(r.sessions)}</span> },
    { key: 'engagement_rate', header: ar ? 'التفاعل' : 'Engagement', sortable: true, align: 'end', hideable: true, value: (r) => r.engagement_rate ?? -1, render: (r) => <span dir="ltr" className="tnum">{r.engagement_rate != null ? percent(r.engagement_rate, 0) : '—'}</span> },
    { key: 'purchases', header: ar ? 'المشتريات' : 'Purchases', sortable: true, align: 'end', value: (r) => r.purchases ?? -1, render: (r) => <span dir="ltr" className="tnum">{num(r.purchases)}</span> },
    { key: 'add_to_carts', header: ar ? 'إضافات للسلة' : 'Add to carts', sortable: true, align: 'end', hideable: true, defaultHidden: true, render: (r) => <span dir="ltr" className="tnum">{num(r.add_to_carts ?? null)}</span> },
    { key: 'checkouts', header: ar ? 'بدء الدفع' : 'Checkouts', sortable: true, align: 'end', hideable: true, defaultHidden: true, render: (r) => <span dir="ltr" className="tnum">{num(r.checkouts ?? null)}</span> },
    { key: 'conversion_rate', header: ar ? 'التحويل' : 'Conversion', sortable: true, align: 'end', hideable: true, value: (r) => r.conversion_rate ?? -1, render: (r) => <span dir="ltr" className="tnum">{r.conversion_rate != null ? percent(r.conversion_rate, 2) : '—'}</span> },
    { key: 'revenue', header: ar ? 'الإيراد' : 'Revenue', sortable: true, align: 'end', value: (r) => r.revenue ?? -1, render: (r) => <span dir="ltr" className="tnum">{r.revenue != null ? money(r.revenue, cur) : '—'}</span> },
  ]

  const download = async (format: 'csv' | 'xlsx') => {
    // Page and page size are deliberately NOT sent: the file carries every row in scope for this view and filter.
    const p = new URLSearchParams(params)
    p.delete('page'); p.delete('per_page'); p.set('format', format)
    const res = await api.get(`${base}/export?${p.toString()}`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data as Blob)
    const a = document.createElement('a')
    a.href = url; a.download = `traffic-${breakdown}.${format}`; a.click()
    URL.revokeObjectURL(url)
  }

  const crumbs: Array<{ label: string; to: Chain }> = [{ label: ar ? 'القنوات' : 'Channels', to: {} }]
  if (chain.channel) crumbs.push({ label: chain.channel, to: { channel: chain.channel } })
  if (chain.sourceMedium) crumbs.push({ label: chain.sourceMedium, to: { channel: chain.channel, sourceMedium: chain.sourceMedium } })
  if (chain.campaign) crumbs.push({ label: chain.campaign, to: { ...chain } })

  return (
    <ChartCard title={ar ? 'مصادر الزيارات وUTM' : 'Traffic sources and UTM'} subtitle={ar ? 'التقسيم والفرز والبحث على الخادم ضمن مشروعك فقط.' : 'Paging, sort and search run on the server, inside this project only.'}>
      <div data-testid="site-traffic" className="min-w-0 space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <Select aria-label={ar ? 'العرض' : 'View'} value={view} onChange={(e) => { setView(e.target.value as View); reset() }}
            options={VIEWS.map((v) => ({ value: v.id, label: ar ? v.ar : v.en }))} className="max-w-full sm:max-w-sm" />
          <button type="button" onClick={() => void download('csv')} data-testid="traffic-export-csv" className="rounded-[8px] border border-border px-2.5 py-1.5 text-xs font-semibold">CSV</button>
          <button type="button" onClick={() => void download('xlsx')} data-testid="traffic-export-xlsx" className="rounded-[8px] border border-border px-2.5 py-1.5 text-xs font-semibold">XLSX</button>
        </div>
        {drill && (
          <nav aria-label={ar ? 'مسار التعمّق' : 'Drill path'} data-testid="traffic-crumbs" className="flex flex-wrap items-center gap-1 text-xs">
            {crumbs.map((c, i) => (
              <span key={i} className="flex items-center gap-1">
                {i > 0 && <span aria-hidden>›</span>}
                <button type="button" onClick={() => go(c.to)} className="max-w-[12rem] truncate font-semibold text-brand-600 hover:underline" title={c.label} dir="auto" disabled={i === crumbs.length - 1}>{c.label}</button>
              </span>
            ))}
          </nav>
        )}
        {q.isError ? (
          <QueryFailure error={q.error} ar={ar} testId="site-traffic-failure" onRetry={() => void q.refetch()} fallbackTitle={ar ? 'تعذّرت قراءة جدول الزيارات' : 'The traffic table could not be read'} />
        ) : q.data && q.data.state !== 'ready' ? (
          <p data-testid="site-traffic-absent" className="text-sm text-text-muted">{ar ? 'لا خاصية الموقع محددة لهذا المشروع.' : 'No Website property is selected for this project.'}</p>
        ) : (
          <DataTable<TrafficRow>
            columns={columns} rows={q.data?.rows ?? []} loading={q.isLoading}
            rowKey={(r) => `${r.dimension_1}\u001f${r.dimension_2 ?? ''}`}
            stateKey={`traffic-columns:${projectId}`} columnPicker labels={labels} emptyTitle={ar ? 'لا زيارات مطابقة' : 'No matching traffic'}
            expandRow={(r) => (
              <dl data-testid="traffic-row-detail" className="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
                {([[ar ? 'جلسات متفاعلة' : 'Engaged sessions', r.engaged_sessions], [ar ? 'أحداث رئيسية' : 'Key events', r.key_events], [ar ? 'إضافات للسلة' : 'Add to carts', r.add_to_carts], [ar ? 'بدء الدفع' : 'Checkouts', r.checkouts]] as Array<[string, number | null | undefined]>).map(([k, v]) => (
                  <div key={k}><dt className="text-text-muted">{k}</dt><dd className="font-semibold"><Num className="tnum">{num(v ?? null)}</Num></dd></div>
                ))}
              </dl>
            )}
            server={{ total: q.data?.total ?? 0, state: table, onChange: setTable }} maxHeight="32rem"
          />
        )}
      </div>
    </ChartCard>
  )
}
