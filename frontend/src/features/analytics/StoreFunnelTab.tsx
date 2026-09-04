import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, HelpCircle, Store, TrendingDown } from 'lucide-react'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { Panel } from './components'
import { money, moneyExact, num, ratio } from './format'
import { getData } from '@/lib/api/client'
import { Badge } from '@/components/ui/Badge'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { QueryFailure } from '@/components/ui/QueryFailure'

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
  source: { kind: 'stores' | 'ad_platforms' | 'none'; ar: string; en: string }
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

const SOURCE_LABEL: Record<Stage['source']['kind'], { ar: string; en: string; tone: 'success' | 'info' | 'neutral' }> = {
  stores: { ar: 'المتجر', en: 'Store', tone: 'success' },
  ad_platforms: { ar: 'بكسل المنصات', en: 'Platform pixel', tone: 'info' },
  none: { ar: 'لا يوجد مصدر', en: 'No source', tone: 'neutral' },
}

/**
 * The funnel API states rates as PERCENTAGES (3.5 means 3.5%), not as ratios.
 *
 * `format.percent()` multiplies by a hundred, which is right for the ratio-shaped figures everywhere
 * else on this page and wrong here — it printed a 3.5% click-through as «350.0%» and a drop-off as
 * «9650.0%». Caught by looking at the rendered page. Keeping the API self-describing and converting at
 * the one place that consumes it is better than making one endpoint speak a different dialect.
 */
function rate(n: number | null): string {
  return n === null ? '—' : `${n.toFixed(1)}%`
}

const METHOD_AR: Record<string, string> = {
  utm_campaign_id: 'معرّف الحملة في UTM',
  utm_campaign_name: 'اسم الحملة في UTM',
  click_id_platform_only: 'معرّف النقرة — المنصة فقط',
  utm_source_platform_only: 'مصدر UTM — المنصة فقط',
  conflict: 'تعارض بين معرّف النقرة والحملة',
  none: 'بلا إسناد',
}

export function StoreFunnelTab({ projectId, range }: { projectId: string | null; range: { from: string; to: string } }) {
  const ar = useUi((s) => s.locale) === 'ar'

  const q = useQuery({
    queryKey: ['store-funnel', projectId, range.from, range.to],
    queryFn: () => getData<FunnelPayload>(`/projects/${projectId}/commerce/funnel?from=${range.from}&to=${range.to}`),
    enabled: Boolean(projectId),
  })

  if (!projectId) return <EmptyState title={ar ? 'اختر مشروعًا' : 'Pick a project'} description={ar ? 'الفانل والمتجر يُقرآن على مستوى المشروع.' : 'The funnel is read per project.'} />
  if (q.isLoading) return <Skeleton className="h-96" />
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} testId="store-funnel-failure" onRetry={() => void q.refetch()}
      fallbackTitle={ar ? 'تعذّر تحميل الفانل.' : 'The funnel could not be loaded.'} />
  }

  const { stages, steps, totals, derived, comparisons, coverage } = q.data
  // One currency for the whole page, named by the server. Falling back to SAR keeps an older payload
  // rendering, but nothing on this page decides a currency for itself any more.
  const cur = totals.reporting_currency || 'SAR'
  const stepFrom = new Map(steps.map((s) => [s.to, s]))
  const widest = Math.max(...stages.map((s) => s.value ?? 0), 1)

  return (
    <div className="space-y-4">
      {coverage.stores === 0 && (
        <p data-testid="funnel-no-store" className="flex items-start gap-2 rounded-xl bg-[var(--warning-background)] px-4 py-3 text-sm text-warning">
          <Store size={15} className="mt-0.5 shrink-0" />
          {ar
            ? 'لا يوجد متجر مربوط بهذا المشروع، لذلك مراحل الطلبات والإيرادات لا مصدر لها. اربط سلة أو زد من صفحة التكاملات.'
            : 'No store is connected to this project, so the order and revenue stages have no source. Connect Salla or Zid from the integrations page.'}
        </p>
      )}

      {/*
        The reading before the stages, not after them.

        The drop between two stages is the only thing anybody opens this section for, and it was left
        for the reader to compute — every time, from figures whose sources differ: the click is the
        platform's claim, the order is the merchant's ledger.
      */}
      {q.data.reading && <FunnelReadingBlock reading={q.data.reading} ar={ar} />}

      <Panel title={ar ? 'الفانل: من الظهور إلى الإيراد' : 'Funnel: impression to revenue'}>
        <ol data-testid="funnel-stages" className="space-y-2">
          {stages.map((stage) => {
            const source = SOURCE_LABEL[stage.source.kind]
            const step = stepFrom.get(stage.key)
            const width = stage.value === null ? 0 : Math.max(2, (stage.value / widest) * 100)

            return (
              <li key={stage.key} data-testid={`funnel-stage-${stage.key}`} className="rounded-xl border border-border bg-surface p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="flex items-center gap-2">
                    <span className="font-semibold text-text-primary">{ar ? stage.label_ar : stage.label_en}</span>
                    <Badge tone={source.tone}>{ar ? source.ar : source.en}</Badge>
                    {stage.state === 'partial' && <Badge tone="warning">{ar ? 'ناقص' : 'Undercount'}</Badge>}
                  </span>
                  <span className="tnum text-lg font-extrabold text-text-primary">
                    {stage.value === null
                      ? <span data-testid={`funnel-unmeasured-${stage.key}`} className="text-sm font-semibold text-text-muted">{ar ? 'لا يُقاس' : 'Not measured'}</span>
                      : stage.key === 'revenue' ? money(stage.value, cur) : num(stage.value)}
                  </span>
                </div>

                {stage.value !== null && (
                  <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-surface-secondary">
                    <div className="h-full rounded-full bg-[var(--brand)]" style={{ width: `${width}%` }} />
                  </div>
                )}

                {/* The provenance, said in words rather than implied by a colour. */}
                <p className="mt-1.5 flex items-start gap-1.5 text-[11px] text-text-muted">
                  <HelpCircle size={12} className="mt-0.5 shrink-0" />
                  {ar ? stage.source.ar : stage.source.en}
                </p>

                {(stage.note_ar || stage.note_en) && (
                  <p data-testid={`funnel-note-${stage.key}`} className="mt-1 flex items-start gap-1.5 text-[11px] text-warning">
                    <AlertTriangle size={12} className="mt-0.5 shrink-0" />
                    {ar ? stage.note_ar : stage.note_en}
                  </p>
                )}

                {step && step.conversion_rate !== null && (
                  <p className="mt-1.5 flex flex-wrap items-center gap-x-3 text-[11px] text-text-secondary">
                    <span className="inline-flex items-center gap-1">
                      <TrendingDown size={12} className="text-danger" />
                      {ar ? 'التسرّب' : 'Drop-off'} <span className="tnum font-semibold">{rate(step.drop_off)}</span>
                    </span>
                    <span>
                      {ar ? 'معدل التحويل' : 'Conversion'} <span className="tnum font-semibold">{rate(step.conversion_rate)}</span>
                    </span>
                    {step.spans_unmeasured_stages && (
                      <span data-testid={`funnel-span-${stage.key}`} className="text-text-muted">
                        {ar ? '— يتخطّى مراحل غير مقيسة' : '— spans unmeasured stages'}
                      </span>
                    )}
                  </p>
                )}
              </li>
            )
          })}
        </ol>
      </Panel>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Metric label={ar ? 'العائد على الإنفاق' : 'ROAS'} value={derived.roas === null ? null : ratio(derived.roas)} hint={ar ? 'على الإيراد الصافي بعد الاسترداد' : 'On net revenue, after refunds'} />
        <Metric label={ar ? 'ROAS المُسند' : 'Attributed ROAS'} value={derived.attributed_roas === null ? null : ratio(derived.attributed_roas)} hint={ar ? 'الطلبات التي أمكن ربطها بحملة فقط' : 'Only orders traceable to a campaign'} />
        <Metric label={ar ? 'متوسط قيمة الطلب' : 'AOV'} value={derived.aov === null ? null : money(derived.aov, cur)} />
        <Metric label={ar ? 'تكلفة الطلب' : 'CPA'} value={derived.cpa === null ? null : moneyExact(derived.cpa, cur ?? null)} hint={ar ? 'الإنفاق ÷ الطلبات' : 'Spend ÷ orders'} />
        <Metric
          label={ar ? 'تكلفة اكتساب العميل' : 'CAC'}
          value={derived.cac === null ? null : money(derived.cac, cur)}
          hint={ar ? 'الإنفاق ÷ العملاء الجدد — وليس كل الطلبات' : 'Spend ÷ NEW customers — not all orders'}
        />
        <Metric label={ar ? 'من النقرة إلى الطلب' : 'Click → order'} value={rate(derived.conversion_rate)} />
      </div>

      {/*
        Gross → refunded → net, because the page already subtracts and never says how much.
        
        Every money figure here is NET: the ROAS card's own hint says «on net revenue, after
        refunds». So a merchant reading «revenue 100,000, ROAS 4x» is told a subtraction happened and
        cannot see its size — 500 refunded and 50,000 refunded produce the same screen, and they are
        not the same month. The funnel has computed `refunded` and `gross_revenue` since it shipped
        and nothing rendered either.
        
        Cancelled orders sit here for the same reason: an order that never completed is not a refund
        and not a sale, and it is the other way the order count and the money can disagree.
      */}
      <Panel title={ar ? 'ما لم يبقَ' : 'What did not stick'}>
        <dl data-testid="funnel-refunds" className="grid gap-3 text-sm sm:grid-cols-3">
          <Fact label={ar ? 'الإيراد قبل الاسترداد' : 'Revenue before refunds'} value={money(totals.gross_revenue, cur)} />
          <Fact
            label={ar ? 'المسترد' : 'Refunded'}
            value={money(totals.refunded, cur)}
            /* Zero is the ordinary answer and must not be dressed as a warning. */
            tone={totals.refunded > 0 ? 'warning' : undefined}
          />
          <Fact label={ar ? 'طلبات ملغاة' : 'Cancelled orders'} value={num(totals.cancelled_orders)} tone={totals.cancelled_orders > 0 ? 'warning' : undefined} />
        </dl>
        <p className="mt-2 text-[11px] text-text-muted">
          {ar
            ? 'كل مبلغ في هذه الصفحة صافٍ بعد الاسترداد — وهذا هو مقدار ما طُرح. الطلب الملغى لم يُحتسب إيرادًا أصلًا، فهو ليس استردادًا.'
            : 'Every amount on this page is net of refunds — this is how much was taken off. A cancelled order was never counted as revenue, so it is not a refund.'}
        </p>
      </Panel>

      <Panel title={ar ? 'صدق الإسناد' : 'Attribution honesty'}>
        <dl data-testid="funnel-attribution" className="grid gap-3 text-sm sm:grid-cols-3">
          <Fact label={ar ? 'طلبات مُسندة لحملة' : 'Orders traced to a campaign'} value={num(totals.attributed_orders)} />
          <Fact label={ar ? 'طلبات بلا إسناد' : 'Orders with no attribution'} value={num(coverage.orders_without_attribution)} tone="warning" />
          <Fact label={ar ? 'إيراد مُسند' : 'Attributed revenue'} value={money(totals.attributed_revenue, cur)} />
        </dl>
        <p className="mt-2 text-[11px] text-text-muted">
          {ar
            ? 'الطلبات التي تعذّر ربطها بحملة تُعرض هنا ولا تُوزَّع على الحملات. نسبة مرتفعة منها تعني مشكلة في وسوم الروابط قبل أن تعني أي شيء عن الأداء.'
            : 'Orders that could not be traced are shown here and never spread across campaigns. A high share of them is a link-tagging problem before it is anything about performance.'}
        </p>
      </Panel>

      {comparisons.platforms.length > 0 && (
        <Panel title={ar ? 'المقارنة بين المنصات' : 'Across platforms'}>
          <Table
            head={[ar ? 'المنصة' : 'Platform', ar ? 'الإنفاق' : 'Spend', ar ? 'الطلبات' : 'Orders', ar ? 'الإيراد' : 'Revenue', 'ROAS']}
            rows={comparisons.platforms.map((p) => [p.platform, money(p.spend, cur), num(p.orders), money(p.revenue, cur), ratio(p.roas)])}
            values={comparisons.platforms.map((p) => [p.platform, p.spend, p.orders, p.revenue, p.roas])}
          />
        </Panel>
      )}

      {comparisons.campaigns.length > 0 && (
        <Panel title={ar ? 'المقارنة بين الحملات' : 'Across campaigns'}>
          <Table
            head={[ar ? 'الحملة' : 'Campaign', ar ? 'الطلبات' : 'Orders', ar ? 'الإيراد' : 'Revenue', ar ? 'طريقة الإسناد' : 'How it was traced']}
            rows={comparisons.campaigns.map((c) => [
              c.external_campaign_id.slice(0, 8),
              num(c.orders),
              money(c.revenue, cur),
              ar ? (METHOD_AR[c.attribution_method ?? 'none'] ?? c.attribution_method) : c.attribution_method,
            ])}
            values={comparisons.campaigns.map((c) => [
              c.external_campaign_id, c.orders, c.revenue, c.attribution_method ?? null,
            ])}
          />
        </Panel>
      )}

      {comparisons.products.length > 0 && (
        <Panel title={ar ? 'المنتجات الأكثر مبيعًا' : 'Best sellers'}>
          <Table
            head={[ar ? 'المنتج' : 'Product', ar ? 'الكمية' : 'Quantity', ar ? 'الإيراد' : 'Revenue']}
            rows={comparisons.products.map((p) => [p.name, num(p.quantity), money(p.revenue, cur)])}
            values={comparisons.products.map((p) => [p.name, p.quantity, p.revenue])}
          />
        </Panel>
      )}

      {/* The footer that says what this page could NOT see. */}
      <p data-testid="funnel-coverage" className="rounded-xl bg-surface-hover px-4 py-3 text-[11px] text-text-secondary">
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
  )
}

function Metric({ label, value, hint }: { label: string; value: string | null; hint?: string }) {
  return (
    <div className="rounded-xl border border-border bg-surface p-3">
      <p className="text-xs text-text-secondary">{label}</p>
      <p className="tnum mt-0.5 text-xl font-extrabold text-text-primary">{value ?? '—'}</p>
      {hint && <p className="mt-0.5 text-[11px] text-text-muted">{hint}</p>}
    </div>
  )
}

function Fact({ label, value, tone }: { label: string; value: string; tone?: 'warning' }) {
  return (
    <div>
      <dt className="text-xs text-text-secondary">{label}</dt>
      <dd className={`tnum text-lg font-extrabold ${tone === 'warning' ? 'text-warning' : 'text-text-primary'}`}>{value}</dd>
    </div>
  )
}

/**
 * STORE-TABLE-PRESENTATION-001 — the store tables, on the product's own table.
 *
 * This was a fourth hand-rolled table: no sort on any column, every figure left-aligned under a
 * left-aligned header, and its own idea of what a missing value looks like. The store tab is where
 * an operator compares platforms and campaigns against orders that actually happened, and comparing
 * is exactly what a table with no sort cannot help with.
 *
 * `values` carries the raw figures behind the formatted cells, so ordering is done on the number
 * rather than on «1.2K» as a string — and a figure the money contract refused to state sorts LAST
 * rather than as a zero, because «this platform's spend is in another currency» is not the cheapest
 * spend.
 */
function Table({
  head,
  rows,
  values,
}: {
  head: string[]
  rows: Array<Array<string | null>>
  values?: SortValues[]
}) {
  return (
    <MetricTable
      head={head}
      rows={rows.map((row) => row.map((cell) => cell ?? '—'))}
      values={values}
      initialSort={values ? { column: 1, dir: 'desc' } : undefined}
    />
  )
}

/**
 * The five steps, over the funnel's own stages.
 *
 * Absent steps stay absent: there is no signal without two measured stages, and no action without a
 * signal. The silence says WHICH silence — a store that has not synced its orders and a project with
 * no store at all are different situations, and an operator acts on them differently.
 */
function FunnelReadingBlock({ reading, ar }: { reading: FunnelReading; ar: boolean }) {
  const SILENT: Record<string, { ar: string; en: string }> = {
    only_one_stage_is_measured: {
      ar: 'مرحلة واحدة فقط مقيسة في هذه الفترة — لا يمكن قراءة انخفاض بين طرف واحد.',
      en: 'Only one stage is measured in this window — a fall needs two ends.',
    },
    no_stage_could_be_measured: {
      ar: 'لا مرحلة مقيسة في هذه الفترة: لا متجر مربوط أو لم تصل بيانات بعد.',
      en: 'No stage is measured in this window: no store is connected, or nothing has arrived yet.',
    },
    no_stage_fell: {
      ar: 'لم تنخفض أي مرحلة عن سابقتها في هذه الفترة.',
      en: 'No stage fell below the one before it in this window.',
    },
  }

  return (
    <div data-testid="funnel-reading" className="rounded-xl border border-border bg-surface-secondary/40 p-3.5">
      {reading.signal === null ? (
        <p data-testid="funnel-reading-silent" className="text-sm text-text-secondary">
          {ar
            ? SILENT[reading.silent_reason ?? 'no_stage_could_be_measured']?.ar
            : SILENT[reading.silent_reason ?? 'no_stage_could_be_measured']?.en}
        </p>
      ) : (
        <div className="flex flex-col gap-1.5">
          <p className="text-sm text-text-primary">
            {ar ? 'أكبر انخفاض: ' : 'Largest fall: '}
            <span className="font-bold">{ar ? reading.signal.from.label_ar : reading.signal.from.label_en}</span>
            {' → '}
            <span className="font-bold">{ar ? reading.signal.to.label_ar : reading.signal.to.label_en}</span>
            {' · '}
            <span dir="ltr" className="tnum">{Math.round(reading.signal.share * 100)}%</span>
          </p>

          {reading.explanation && (
            <p className="text-xs leading-relaxed text-text-secondary">{ar ? reading.explanation.ar : reading.explanation.en}</p>
          )}

          {reading.action && (
            <p data-testid="funnel-reading-action" className="text-sm font-medium text-text-primary">
              {ar ? reading.action.ar : reading.action.en}
            </p>
          )}
        </div>
      )}

      {reading.unmeasured_stages > 0 && (
        <p data-testid="funnel-reading-unmeasured" className="mt-2 text-[11px] text-text-muted">
          {ar
            ? `${reading.unmeasured_stages} مرحلة بلا قياس في هذه الفترة، وليست طرفًا في أي انخفاض أعلاه.`
            : `${reading.unmeasured_stages} stage(s) are unmeasured in this window and are not an end of any fall above.`}
        </p>
      )}
    </div>
  )
}
