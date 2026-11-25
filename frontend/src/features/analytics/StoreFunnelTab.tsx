import { Store } from 'lucide-react'
import { Panel } from './components'
import { getData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { RefreshNote } from '@/components/ui/RefreshNote'
import { useSwrQuery } from '@/lib/query/swr'
import { useUi } from '@/stores/ui'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { OrderSourcesPanel } from './OrderSourcesPanel'
import { StoreMirror } from './StoreMirror'
import { ThreeSourcesPanel } from './ThreeSourcesPanel'
import { formatPeriodRange } from '@/lib/period/formatPeriodLabel'

/**
 * FUNNEL-001 — «الفانل والمتجر»: impression to riyal, with the source of every number on the row.
 *
 * ## Why each stage shows where it came from
 *
 * The nine stages are measured by different systems. Impressions and clicks come from the ad
 * platforms. Orders and revenue come from the merchant's own store. Add-to-cart can come from either,
 * and the two routinely disagree — the pixel misses ad-blocked sessions, the store misses nothing.
 * Product views and checkout starts are measured by neither, today.
 *
 * A funnel of nine numbers with no provenance makes all of that invisible. So every row carries its
 * source, and a stage nothing measures shows «لا يوجد مصدر» and the reason — never a zero. A zero is a
 * measurement: it says nobody added anything to a cart. That is a different sentence entirely, and it
 * would send a merchant to fix a product page instead of connecting an analytics integration.
 */

interface Stage {
  key: string
  label_ar: string
  label_en: string
  value: number | null
  state: 'measured' | 'partial' | 'unavailable'
  source: { kind: 'stores' | 'ad_platforms' | 'ga4' | 'none'; ar: string; en: string }
  note_ar?: string | null
  note_en?: string | null
}

interface Step {
  from: string
  to: string
  conversion_rate: number | null
  drop_off: number | null
  spans_unmeasured_stages: boolean
}

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the funnel's own reading, computed beside the stages it reads.
 *
 * A claim about where the path loses most is a claim about the data, and a claim computed twice will
 * eventually be made twice differently. The server derives it from the same stages in this payload.
 */
interface FunnelReading {
  signal: {
    from: { key: string; label_ar: string; label_en: string; value: number }
    to: { key: string; label_ar: string; label_en: string; value: number }
    lost: number
    share: number
    same_source: boolean
  } | null
  context: { stages_measured: number; stages_total: number } | null
  explanation: { ar: string; en: string } | null
  evidence: string[]
  action: { ar: string; en: string } | null
  silent_reason: string | null
  unmeasured_stages: number
}

interface FunnelPayload {
  window: { from: string; to: string }
  stages: Stage[]
  reading?: FunnelReading
  steps: Step[]
  totals: {
    /**
     * COMMERCE-FX-001 — the currency EVERY figure on this page is stated in.
     *
     * Store money is converted at import and ad spend at ingest, both into this currency, which is
     * what makes dividing one by the other for ROAS legitimate. The page used to print «SAR» beside
     * every number regardless; a client reporting in another currency was told riyals.
     */
    reporting_currency: string
    spend: number
    revenue: number
    gross_revenue: number
    refunded: number
    cancelled_orders: number
    orders: number
    new_customers: number
    attributed_orders: number
    attributed_revenue: number
    unattributed_orders: number
  }
  derived: {
    cpa: number | null
    cac: number | null
    aov: number | null
    roas: number | null
    attributed_roas: number | null
    conversion_rate: number | null
  }
  comparisons: {
    platforms: Array<{ platform: string; spend: number; orders: number; revenue: number; roas: number | null }>
    campaigns: Array<{ external_campaign_id: string; orders: number; revenue: number; attribution_method: string | null }>
    products: Array<{ name: string; quantity: number; revenue: number }>
  }
  coverage: {
    stores: number
    stores_without_cart_data: Array<{ id: string; name: string; provider: string }>
    store_last_synced_at: string | null
    orders_in_window: number
    orders_without_attribution: number
    reporting_currency: string
    /**
     * Orders whose conversion could not be vouched for, and are therefore MISSING from every total
     * above. A short total looks exactly like a complete one, so it is counted and said out loud.
     */
    orders_with_money_withheld: number
    money_withheld_currencies: string[]
    reporting_timezone?: string
    orders_with_assumed_timezone?: number
  }
}




export function StoreFunnelTab({ projectId, range }: { projectId: string | null; range: { from: string; to: string } }) {
  const ar = useUi((s) => s.locale) === 'ar'

  // PERF-INSTANT-DATA-001 — scope = the project; the period is the only thing an old answer may bridge.
  const q = useSwrQuery({
    name: 'store-funnel',
    scope: [projectId],
    window: [range.from, range.to],
    queryFn: () => getData<FunnelPayload>(`/projects/${projectId}/commerce/funnel?from=${range.from}&to=${range.to}`),
    enabled: Boolean(projectId),
  })

  if (!projectId) return <EmptyState title={ar ? 'اختر مشروعًا' : 'Pick a project'} description={ar ? 'الفانل والمتجر يُقرآن على مستوى المشروع.' : 'The funnel is read per project.'} />
  if (q.isLoading) return <FunnelSkeleton ar={ar} />
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} testId="store-funnel-failure" onRetry={() => void q.refetch()}
      fallbackTitle={ar ? 'تعذّر تحميل الفانل.' : 'The funnel could not be loaded.'} />
  }

  const { totals, coverage } = q.data
  const note = (
    <RefreshNote ar={ar} isRefreshing={q.isRefreshing} isShowingPrevious={q.isShowingPrevious} refreshFailed={q.refreshFailed}
      updatedAt={q.updatedAt} shownPeriod={formatPeriodRange(q.data.window.from, q.data.window.to, ar ? 'ar' : 'en')} onRetry={() => void q.refetch()} />
  )
  // One currency for the whole page, named by the server. Falling back to SAR keeps an older payload
  // rendering, but nothing on this page decides a currency for itself any more.
  // FX-APPLY-001 — the store figures' own reporting currency, never a guessed «SAR».
  const cur = totals.reporting_currency || null

  return (
    <div data-testid="store-funnel" aria-busy={q.isShowingPrevious} className={`space-y-4 transition-opacity duration-[var(--motion-panel)] ease-[var(--motion-ease)] ${q.isShowingPrevious ? 'opacity-60' : ''}`}>
      <div className="flex min-h-5 items-center">{note}</div>
      {/*
        STORE-MIRROR-001 (Owner 2026-11-25) — «اجعلها بنفس فكرة تحليلات الموقع … جزء المتجر يوازي جزء الموقع». The tab opens the way the
        website tab does: one header line, then the store's own reading in the website's own shapes. Without a store it is the
        website tab's not-connected state: one chip and where to connect it.
      */}
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-secondary">
        <Badge tone="success">{ar ? 'المتجر' : 'Store'}</Badge>
        {coverage.store_last_synced_at && <span data-testid="store-synced">{ar ? 'آخر تحديث' : 'Updated'} <span className="tnum" dir="ltr">{new Date(coverage.store_last_synced_at).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB', { dateStyle: 'short', timeStyle: 'short' })}</span></span>}
        <Badge tone="neutral">{ar ? 'مستقل عن المنصات' : 'Separate from platforms'}</Badge>
      </div>
      {coverage.stores === 0 ? (
        <div data-testid="funnel-no-store" className="flex flex-wrap items-center gap-3 rounded-2xl border border-dashed border-border bg-surface p-5">
          <Badge tone="warning"><span className="inline-flex items-center gap-1"><Store size={12} aria-hidden />{ar ? 'المتجر غير مربوط' : 'Store not connected'}</span></Badge>
          <span className="text-xs text-text-muted">{ar ? 'يُربط سلة أو زد من قسم الربط والتكاملات.' : 'Connect Salla or Zid from the integrations section.'}</span>
        </div>
      ) : (
        <StoreMirror projectId={projectId} range={range} ar={ar} spend={totals.spend ?? null} />
      )}
      {/* COMMERCE-FX-001 — a total short by an unconvertible order says so where the totals are, not only inside the details. */}
      {coverage.orders_with_money_withheld > 0 && (
        <p data-testid="store-money-withheld" className="text-xs text-warning">
          {ar
            ? `${coverage.orders_with_money_withheld} طلبًا بعملة (${coverage.money_withheld_currencies.join('، ')}) بلا سعر صرف مؤرّخ — غير محتسبة في المبالغ أعلاه.`
            : `${coverage.orders_with_money_withheld} order(s) in ${coverage.money_withheld_currencies.join(', ')} have no dated rate — not in the amounts above.`}
        </p>
      )}

      {/*
        STORE-TAB-STORE-ONLY-001 (Owner 2026-11-26) — «قمع المنصات الإعلانية مازال موجود وكبير الحجم وغير مناسب». The store tab's source
        is the store, as the website tab's is GA4: the ad-platform pixel funnel and its tables belong to the platforms tabs, not here.
        With no store the tab is the one chip above; with a store, the details fold only what the store itself states.
      */}
      {coverage.stores > 0 && (
      <details data-testid="store-technical-details" className="rounded-2xl border border-border bg-surface">
        <summary className="cursor-pointer select-none px-4 py-3 text-sm font-extrabold text-text-primary">{ar ? 'التفاصيل الفنية' : 'Technical details'}</summary>
        <div className="space-y-4 border-t border-border p-3">
      <OrderSourcesPanel projectId={projectId} range={range} ar={ar} />
      <ThreeSourcesPanel projectId={projectId} range={range} ar={ar} />

      {/* The footer that says what this page could NOT see. */}
      <p data-testid="funnel-coverage" className="rounded-xl bg-surface-hover px-4 py-3 text-xs text-text-secondary">
        {ar ? 'التغطية' : 'Coverage'}:{' '}
        <span className="tnum">{coverage.stores}</span> {ar ? 'متجر' : 'store(s)'} ·{' '}
        <span className="tnum">{coverage.orders_in_window}</span> {ar ? 'طلبًا في الفترة' : 'orders in the period'} ·{' '}
        {ar ? 'كل المبالغ بـ' : 'All amounts in'} <span className="tnum">{cur}</span>
        {/*
          COMMERCE-TZ-001 — «5 August» is a different sixty thousand seconds in every timezone, so
          the window names the clock it was measured on rather than leaving a reader to assume theirs.
        */}
        {coverage.reporting_timezone && (
          <>
            {' · '}
            {ar ? 'الأيام محسوبة بتوقيت' : 'Days measured in'}{' '}
            <span data-testid="funnel-reporting-timezone" className="tnum" dir="ltr">{coverage.reporting_timezone}</span>
          </>
        )}
        {(coverage.orders_with_assumed_timezone ?? 0) > 0 && (
          <span data-testid="funnel-assumed-timezone" className="block text-warning">
            {ar
              ? `${coverage.orders_with_assumed_timezone} طلبًا لم يذكر متجرها المنطقة الزمنية، فاعتُبرت UTC — قد يقع أيٌّ منها في اليوم السابق أو التالي.`
              : `${coverage.orders_with_assumed_timezone} order(s) come from a store that states no timezone, so UTC was assumed — any of them may belong to the day before or after.`}
          </span>
        )}
        {/*
          COMMERCE-FX-001 — a total short by an unconvertible order looks exactly like a complete one,
          so the shortfall is stated. Silence here would be a claim that the revenue above is whole.
        */}
        {coverage.orders_with_money_withheld > 0 && (
          <span data-testid="funnel-money-withheld" className="block text-warning">
            {ar
              ? `${coverage.orders_with_money_withheld} طلبًا بعملة (${coverage.money_withheld_currencies.join('، ')}) لا يوجد لها سعر صرف مؤرّخ، فلم تُحتسب ضمن الإيراد أعلاه. المبالغ الأصلية محفوظة وتُحتسب فور توفّر السعر.`
              : `${coverage.orders_with_money_withheld} order(s) in ${coverage.money_withheld_currencies.join(', ')} have no dated exchange rate, so they are NOT included in the revenue above. The original amounts are kept and will count as soon as a rate exists.`}
          </span>
        )}
        {coverage.stores_without_cart_data.length > 0 && (
          <span className="block text-warning">
            {ar
              ? `لا تُتيح المنصة السلات المتروكة لـ: ${coverage.stores_without_cart_data.map((s) => s.name).join('، ')}`
              : `Abandoned carts are not offered by the platform for: ${coverage.stores_without_cart_data.map((s) => s.name).join(', ')}`}
          </span>
        )}
        {coverage.store_last_synced_at && (
          <span className="block">
            {ar ? 'آخر مزامنة للمتجر' : 'Store last synced'}:{' '}
            <span className="tnum">{new Date(coverage.store_last_synced_at).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB')}</span>
          </span>
        )}
      </p>
        </div>
      </details>
      )}
    </div>
  )
}

/** The cold open: the panel's own title and the stage rows at their final height, not one slab. */
function FunnelSkeleton({ ar }: { ar: boolean }) {
  return (
    <div data-testid="store-funnel-skeleton" aria-busy="true" aria-label={ar ? 'جارٍ تحميل الفانل' : 'Loading the funnel'} className="space-y-4">
      <Panel title={ar ? 'الفانل: من الظهور إلى الإيراد' : 'Funnel: impression to revenue'}>
        <ol className="space-y-2">
          {Array.from({ length: 6 }, (_, i) => <li key={i}><Skeleton className="h-[5.5rem] w-full" /></li>)}
        </ol>
      </Panel>
    </div>
  )
}
