import { Num } from '@/components/ui/Num'
import { numberFormatter } from '@/lib/numerals'
import { withMark } from '@/lib/money/mark'
import type { ReportAd } from './ReportAdsSection'
import { ContentScoreCard } from '@/features/content/ContentScoreCard'
import { monotoneSteps } from '@/features/content/contentScore'

/**
 * REPORT-CONTENT-DEEP-001 — Owner 2026-11-17: «احتاج إضافة تفاصيل كثيرة داخل تفاصيل كل محتوى».
 *
 * The content dialog stated seven headline figures while the payload already carried the content's whole path: its revenue,
 * purchases, carts, landing-page visits, leads, video views and quartiles, and the days it ran. This draws them — a row of
 * extra figures, the viewer's journey from impression to purchase, and how much of the video was watched — from figures the
 * platform reported, never a zero for «not reported», and never a cost built on a spend that is partly unconverted.
 */
type Bag = Record<string, unknown>

const reported = (bag: Bag, key: string): number | null => {
  const v = bag[key]
  if (typeof v !== 'number' || !Number.isFinite(v)) return null
  const flags = bag.reported as Record<string, boolean> | undefined

  return flags && flags[key] === false ? null : v
}

const fmt = (v: number, digits = 0) => numberFormatter('en-US', { maximumFractionDigits: digits }).format(v)

const COUNTS: Array<{ key: string; ar: string; en: string }> = [
  { key: 'purchases', ar: 'الطلبات', en: 'Orders' },
  { key: 'add_to_cart', ar: 'الإضافة للسلة', en: 'Add to cart' },
  { key: 'checkout', ar: 'بدء الدفع', en: 'Checkouts' },
  { key: 'landing_page_views', ar: 'زيارات الصفحة', en: 'Page views' },
  { key: 'leads', ar: 'العملاء المحتملون', en: 'Leads' },
  { key: 'video_views', ar: 'المشاهدات', en: 'Video views' },
  { key: 'active_days', ar: 'مدة التشغيل', en: 'Days running' },
]

const JOURNEY: Array<{ key: string; ar: string; en: string }> = [
  { key: 'impressions', ar: 'الظهور', en: 'Impressions' },
  { key: 'clicks', ar: 'النقرات', en: 'Clicks' },
  { key: 'landing_page_views', ar: 'زيارات الصفحة', en: 'Page views' },
  { key: 'add_to_cart', ar: 'السلة', en: 'Cart' },
  { key: 'purchases', ar: 'الطلب', en: 'Order' },
]

const QUARTILES: Array<{ key: string; label: string }> = [
  { key: 'video_p25', label: '25%' },
  { key: 'video_p50', label: '50%' },
  { key: 'video_p75', label: '75%' },
  { key: 'video_p100', label: '100%' },
]

export function deepFigures(ad: ReportAd, currency: string | null, ar: boolean): Array<{ label: string; value: string }> {
  const bag = ad as Bag
  const out: Array<{ label: string; value: string }> = []
  const spendWhole = !(typeof bag.spend_withheld_rows === 'number' && bag.spend_withheld_rows > 0)
  const spend = spendWhole ? reported(bag, 'spend') : null
  const revenue = typeof bag.revenue_withheld_rows === 'number' && bag.revenue_withheld_rows > 0 ? null : reported(bag, 'revenue')

  if (revenue !== null && revenue > 0) out.push({ label: ar ? 'الإيرادات' : 'Revenue', value: withMark(fmt(revenue), currency) })
  for (const c of COUNTS) {
    const v = reported(bag, c.key)
    if (v !== null && v > 0) out.push({ label: ar ? c.ar : c.en, value: fmt(v) })
  }
  const clicks = reported(bag, 'clicks')
  const impressions = reported(bag, 'impressions')
  if (spend !== null && spend > 0 && clicks !== null && clicks > 0) out.push({ label: ar ? 'تكلفة النقرة' : 'CPC', value: withMark(fmt(spend / clicks, 2), currency) })
  if (spend !== null && spend > 0 && impressions !== null && impressions > 0) out.push({ label: ar ? 'تكلفة الألف ظهور' : 'CPM', value: withMark(fmt((spend / impressions) * 1000, 2), currency) })

  return out
}

export function ReportAdDeepFigures({ ad, currency, ar }: { ad: ReportAd; currency: string | null; ar: boolean }) {
  const bag = ad as Bag
  const extra = deepFigures(ad, currency, ar)

  // CONTENT-SCORE-001 — a later stage larger than the one before it is attribution, not the viewer's path: the chain stops there.
  const steps = monotoneSteps(JOURNEY.map((s) => ({ ...s, v: reported(bag, s.key) })).filter((s): s is typeof s & { v: number } => s.v !== null && s.v > 0))
  const first = steps[0]?.v ?? 0

  const views = reported(bag, 'video_views')
  const watched = views !== null && views > 0
    ? QUARTILES.map((q) => ({ ...q, v: reported(bag, q.key) })).filter((q): q is typeof q & { v: number } => q.v !== null)
    : []

  if (extra.length === 0 && steps.length < 3 && watched.length === 0) return null

  return (
    <div data-testid="report-ad-deep" className="grid gap-3">
      <ContentScoreCard ad={bag} ar={ar} />
      {extra.length > 0 && (
        <dl data-testid="report-ad-deep-figures" className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {extra.map((f) => (
            <div key={f.label} className="rounded-lg border border-border p-2 text-center">
              <dt className="text-[11px] font-semibold leading-tight text-text-muted">{f.label}</dt>
              <dd className="tnum text-sm font-bold text-text-primary"><Num>{f.value}</Num></dd>
            </div>
          ))}
        </dl>
      )}

      {steps.length >= 3 && (
        <section data-testid="report-ad-journey" className="rounded-xl border border-border p-3">
          <h4 className="mb-2 text-sm font-bold text-text-primary">{ar ? 'القمع التسويقي' : 'Funnel'}</h4>
          <ol className="grid gap-1.5">
            {steps.map((s, i) => {
              // Square root, so a purchase step is still a visible bar beside a million impressions.
              const width = Math.max(4, Math.round(Math.sqrt(s.v / first) * 100))
              const prev = i > 0 ? steps[i - 1]?.v ?? null : null

              return (
                <li key={s.key} className="grid grid-cols-[5.5rem_1fr_auto] items-center gap-2 text-xs">
                  <span className="truncate font-semibold text-text-secondary">{ar ? s.ar : s.en}</span>
                  <span className="h-2.5 overflow-hidden rounded-full bg-surface-secondary">
                    <span className="block h-full rounded-full bg-brand-500" style={{ width: `${width}%` }} />
                  </span>
                  <span className="tnum font-bold text-text-primary">
                    <Num>{fmt(s.v)}</Num>
                    {prev !== null && prev > 0 && <span className="ms-1 font-semibold text-text-muted"><Num>{`${fmt((s.v / prev) * 100, 1)}%`}</Num></span>}
                  </span>
                </li>
              )
            })}
          </ol>
        </section>
      )}

      {watched.length > 0 && views !== null && (
        <section data-testid="report-ad-retention" className="rounded-xl border border-border p-3">
          <h4 className="mb-2 text-sm font-bold text-text-primary">{ar ? 'نسبة المشاهدة' : 'Video retention'}</h4>
          <div className="grid grid-cols-4 items-end gap-2">
            {watched.map((q) => {
              const share = Math.min(1, q.v / views)

              return (
                <div key={q.key} className="flex flex-col items-center gap-1">
                  <span className="tnum text-[11px] font-bold text-text-primary"><Num>{`${fmt(share * 100, 0)}%`}</Num></span>
                  <span className="flex h-16 w-full items-end overflow-hidden rounded-md bg-surface-secondary">
                    <span className="block w-full rounded-md bg-brand-500" style={{ height: `${Math.max(4, Math.round(share * 100))}%` }} />
                  </span>
                  <span className="text-[10px] font-semibold text-text-muted"><Num>{q.label}</Num></span>
                </div>
              )
            })}
          </div>
        </section>
      )}
    </div>
  )
}
