import { useState } from 'react'
import { ChevronDown, Info } from 'lucide-react'
import { objectiveLabel } from '@/features/campaigns/labels'
import { formatPeriodRange } from '@/lib/period/formatPeriodLabel'

/**
 * REPORT-FOOTER-001 — the ONE footer every report surface ends with.
 *
 * It replaces the large «منهجية التقرير وملاحظات البيانات» section and the note that was repeated
 * under every slide. It lives OUTSIDE the report body: small muted text, a hairline above it, no card
 * and no heading, and it is not a report section — the section system neither lists nor numbers it,
 * so an Owner cannot switch it off and a contents page never promises it.
 *
 * The wording is fixed, not configurable per tenant: it is the same sentence on the page, the link,
 * the deck and the PDF, which is what makes it a footer and not a disclaimer somebody forgot to update.
 */
export const REPORT_NOTE = {
  ar: 'ملاحظة: تُدار الحملات وفق منهجية قائمة على الأداء، وقد تختلف النتائج بحسب الفترة والمنصة ومصدر البيانات ونموذج الإسناد ومرحلة تعلّم الحملة.',
  en: 'Note: Campaigns are managed on a performance-based methodology; results may vary by period, platform, data source, attribution model and the campaign learning phase.',
} as const

/** The longer explanation, kept behind «معرفة المزيد» — never printed unless a reader asks for it. */
export const REPORT_NOTE_MORE = {
  ar: 'ملاحظة: تُدار الحملات وفق منهجية قائمة على الأداء، ولكل قناة تسويقية آلية قياس ونموذج إسناد وفترة احتساب وتحديث خاصة بها. وقد يتفاعل العميل مع عدة قنوات قبل اتخاذ قرار الشراء، لذلك قد يُنسب الطلب لأكثر من قناة تسويقية، دون أن يعني ذلك أن أيًا منها هو المصدر الوحيد للبيع. وتُعد بيانات المتجر الإلكتروني والسجلات المحاسبية المرجع للإيرادات الفعلية، بينما تُستخدم بيانات المنصات الإعلانية لقياس الأداء وفهم مساهمة القنوات في رحلة العميل. كما تتأثر النتائج بطبيعة المنتج والتسعير والعروض والخصومات وجودة المحتوى وسلوك العملاء والمنافسة وتكاليف الإعلان ومرحلة تعلّم الحملات، وقد تتغير الأرقام بعد اكتمال معالجة البيانات. لذلك لا تُعد أرقام الأداء ضمانًا لتحقيق نتائج أو عوائد مستقبلية.',
  en: 'Note: campaigns are managed with a performance-based methodology, and each marketing channel has its own measurement method, attribution model, counting window and update timing. A customer may interact with several channels before deciding to buy, so an order may be attributed to more than one marketing channel without any of them being the sole source of the sale. The online store\'s data and the accounting records are the reference for actual revenue, while advertising platform data is used to measure performance and understand each channel\'s contribution to the customer journey. Results are also affected by the nature of the product, pricing, offers and discounts, content quality, customer behaviour, competition, advertising costs and the campaigns\' learning phase, and figures may change once data processing completes. Performance indicators are therefore not a guarantee of future results or returns.',
} as const

/** REPORT-FRESHNESS-DATE-001 — the footer's date: held at the oldest platform confirmation while any platform is not confirmed fresh. */
const NOT_FRESH = new Set(['delayed', 'stale', 'failed', 'unknown'])

export function reportStamp(summary: { oldest_success_at: string | null; worst_status: string; generated_at: string } | null | undefined): string | null {
  if (!summary) return null
  const oldest = summary.oldest_success_at
  if (NOT_FRESH.has(summary.worst_status) && oldest && Date.parse(oldest) < Date.parse(summary.generated_at)) return oldest

  return summary.generated_at
}

const MORE_LABEL = { ar: { more: 'معرفة المزيد', less: 'إخفاء' }, en: { more: 'Learn more', less: 'Hide' } } as const

export interface ReportFooterFacts {
  /** When these figures were produced — a snapshot's generation time, or a live link's last computation. */
  updatedAt?: string | null
  period?: { from?: string | null; to?: string | null } | null
  currency?: string | null
  timezone?: string | null
  /** The objective the campaigns were bought for, where the report states ONE. */
  objective?: string | null
}

const LABELS = {
  ar: { updated: 'آخر تحديث للتقرير', period: 'الفترة', currency: 'العملة', timezone: 'المنطقة الزمنية', objective: 'هدف الحملة' },
  en: { updated: 'Report last updated', period: 'Period', currency: 'Currency', timezone: 'Time zone', objective: 'Campaign objective' },
} as const

/** The metadata line's items, in the Owner's order; an item with no value is left out, never printed as «—». */
export function reportFooterItems(facts: ReportFooterFacts, locale: 'ar' | 'en'): Array<[string, string]> {
  const t = LABELS[locale]
  const items: Array<[string, string | null]> = [
    [t.updated, facts.updatedAt ? formatStamp(facts.updatedAt, facts.timezone) : null],
    [t.period, facts.period?.from && facts.period?.to ? formatPeriodRange(facts.period.from, facts.period.to, locale) : null],
    [t.currency, facts.currency || null],
    [t.timezone, facts.timezone || null],
    [t.objective, facts.objective ? objectiveLabel(facts.objective, locale) : null],
  ]

  return items.filter((i): i is [string, string] => i[1] !== null && i[1] !== '')
}

/**
 * `2026-10-19 21:17` — the same ISO shape the period uses, so the two read as one family, in both
 * languages and with Latin digits. The long Arabic locale form («2026/10/19، 21:17») wrapped onto two
 * lines inside a card; this one cannot.
 *
 * In the report's time zone (Riyadh unless it states another), never the reader's: the card beside it names the zone, and a client
 * abroad read a different hour than the agency that sent the report.
 */
function formatStamp(iso: string, timezone?: string | null): string {
  const d = new Date(iso)

  if (Number.isNaN(d.getTime())) return iso

  const options = { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' } as const
  let format: Intl.DateTimeFormat
  try {
    format = new Intl.DateTimeFormat('en-GB', { ...options, timeZone: timezone || 'Asia/Riyadh' })
  } catch {
    format = new Intl.DateTimeFormat('en-GB', { ...options, timeZone: 'Asia/Riyadh' })
  }
  const part = (type: Intl.DateTimeFormatPartTypes) => format.formatToParts(d).find((p) => p.type === type)?.value ?? ''

  return `${part('year')}-${part('month')}-${part('day')} ${part('hour')}:${part('minute')}`
}

export function ReportFooter({ facts, locale, className = '' }: { facts: ReportFooterFacts; locale: 'ar' | 'en'; className?: string }) {
  const items = reportFooterItems(facts, locale)
  const [open, setOpen] = useState(false)
  const label = MORE_LABEL[locale]

  /*
   * REPORT-FOOTER-IN-SYSTEM-CARD-001 (Owner, 2026-10-19) — the report ends the way the product does.
   *
   * The note is the SAME card the Analytics and Dashboard pages end with (`PerformanceNotice`, compact):
   * `rounded-xl border bg-surface-secondary`, the info mark, one 13px sentence, a green «معرفة المزيد»
   * with its chevron. The report's facts — last update, period, currency, time zone, objective — are not
   * crammed into that card or beside it: they are their OWN row underneath, one matching card per fact,
   * so the time, the currency and the details read as independent, consistent items.
   *
   * Still outside the report body: no heading, not a section anybody can switch off.
   */
  return (
    <footer data-testid="report-footer" dir={locale === 'ar' ? 'rtl' : 'ltr'} className={`report-footer mt-8 grid w-full min-w-0 gap-2 ${className}`}>
      <div data-testid="report-footer-note-card" className="rounded-xl border border-border bg-surface-secondary px-3.5 py-2.5">
        <div className="flex items-start gap-2">
          <Info size={15} className="mt-0.5 shrink-0 text-text-muted" aria-hidden />
          <div className="min-w-0 flex-1">
            <p data-testid="report-footer-note" className="text-[13px] leading-relaxed text-text-secondary">{REPORT_NOTE[locale]}</p>
            {open && (
              <p data-testid="report-footer-more" className="mt-2 border-t border-border pt-2 text-[13px] leading-relaxed text-text-secondary">{REPORT_NOTE_MORE[locale]}</p>
            )}
            <button
              type="button"
              data-testid="report-footer-toggle"
              aria-expanded={open}
              onClick={() => setOpen((v) => !v)}
              className="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline"
            >
              {open ? label.less : label.more}
              <ChevronDown size={13} className={`transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden />
            </button>
          </div>
        </div>
      </div>
      {items.length > 0 && (
        <dl data-testid="report-footer-meta" className="flex min-w-0 flex-wrap gap-2">
          {items.map(([itemLabel, value]) => (
            <div
              key={itemLabel}
              data-testid="report-footer-fact"
              // The period (two dates and an arrow) is the long one: it grows and wraps, never an ellipsis. The time stays on one line.
              className={`min-w-[8.5rem] rounded-xl border border-border bg-surface-secondary px-3.5 py-2 ${itemLabel === LABELS[locale].period ? 'flex-[2_1_14rem]' : itemLabel === LABELS[locale].updated ? 'flex-[1_0_auto]' : 'flex-1'}`}
            >
              <dt className="text-[11px] text-text-muted">{itemLabel}</dt>
              <dd className={`mt-0.5 text-[13px] font-semibold text-text-primary ${itemLabel === LABELS[locale].updated ? 'whitespace-nowrap' : '[overflow-wrap:anywhere]'}`}><bdi className="tnum">{value}</bdi></dd>
            </div>
          ))}
        </dl>
      )}
    </footer>
  )
}
