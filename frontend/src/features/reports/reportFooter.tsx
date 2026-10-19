import { useState } from 'react'
import { ChevronDown, Info } from 'lucide-react'
import { objectiveLabel } from '@/features/campaigns/labels'

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
  ar: 'تعتمد قراءة النتائج على الأداء والبيانات المتاحة خلال فترة التقرير، وقد تختلف المؤشرات باختلاف مصادر القياس وآليات الإسناد والعوامل المؤثرة على الحملة.',
  en: 'Reading the results depends on the performance and data available during the report period; indicators may differ by measurement sources, attribution methods and other factors affecting the campaign.',
} as const

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
  ar: { updated: 'آخر تحديث', period: 'الفترة', currency: 'العملة', timezone: 'المنطقة الزمنية', objective: 'هدف الحملة' },
  en: { updated: 'Last updated', period: 'Period', currency: 'Currency', timezone: 'Time zone', objective: 'Campaign objective' },
} as const

/** The metadata line's items, in the Owner's order; an item with no value is left out, never printed as «—». */
export function reportFooterItems(facts: ReportFooterFacts, locale: 'ar' | 'en'): Array<[string, string]> {
  const t = LABELS[locale]
  const items: Array<[string, string | null]> = [
    [t.updated, facts.updatedAt ? formatStamp(facts.updatedAt) : null],
    [t.period, facts.period?.from && facts.period?.to ? (facts.period.from === facts.period.to ? facts.period.from : `${facts.period.from} → ${facts.period.to}`) : null],
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
 */
function formatStamp(iso: string): string {
  const d = new Date(iso)

  if (Number.isNaN(d.getTime())) return iso

  const two = (n: number) => String(n).padStart(2, '0')

  return `${d.getFullYear()}-${two(d.getMonth() + 1)}-${two(d.getDate())} ${two(d.getHours())}:${two(d.getMinutes())}`
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
              // The period (two dates and an arrow) is the long one: it grows and wraps, never an ellipsis.
              className={`min-w-[8.5rem] rounded-xl border border-border bg-surface-secondary px-3.5 py-2 ${itemLabel === LABELS[locale].period ? 'flex-[2_1_14rem]' : 'flex-1'}`}
            >
              <dt className="text-[11px] text-text-muted">{itemLabel}</dt>
              <dd className="mt-0.5 text-[13px] font-semibold text-text-primary [overflow-wrap:anywhere]"><bdi className="tnum">{value}</bdi></dd>
            </div>
          ))}
        </dl>
      )}
    </footer>
  )
}
