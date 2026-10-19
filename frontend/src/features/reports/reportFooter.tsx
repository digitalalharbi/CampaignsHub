import { useState } from 'react'
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
    [t.updated, facts.updatedAt ? formatStamp(facts.updatedAt, locale) : null],
    [t.period, facts.period?.from && facts.period?.to ? (facts.period.from === facts.period.to ? facts.period.from : `${facts.period.from} → ${facts.period.to}`) : null],
    [t.currency, facts.currency || null],
    [t.timezone, facts.timezone || null],
    [t.objective, facts.objective ? objectiveLabel(facts.objective, locale) : null],
  ]

  return items.filter((i): i is [string, string] => i[1] !== null && i[1] !== '')
}

function formatStamp(iso: string, locale: 'ar' | 'en'): string {
  const d = new Date(iso)

  if (Number.isNaN(d.getTime())) return iso

  const ar = locale === 'ar'

  return d.toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
}

export function ReportFooter({ facts, locale, className = '' }: { facts: ReportFooterFacts; locale: 'ar' | 'en'; className?: string }) {
  const items = reportFooterItems(facts, locale)
  const [open, setOpen] = useState(false)
  const label = MORE_LABEL[locale]

  /*
   * REPORT-COMPOSITION-CONTROL-001 — a professional full-width footer, aligned with the report
   * container (Owner defect 106): the methodology note on one side, the facts the reader
   * checks — last update, period, currency, time zone, objective — as a labelled grid on the other.
   * One column on a phone; side by side from `lg`. Still outside the report body: no heading and no
   * fill, and not a section anybody can switch off.
   */
  return (
    <footer data-testid="report-footer" dir={locale === 'ar' ? 'rtl' : 'ltr'} className={`report-footer mt-8 w-full min-w-0 rounded-2xl border border-border/60 px-4 py-4 text-start text-[11px] leading-relaxed text-text-muted sm:px-5 ${className}`}>
      <div className={`grid gap-4 ${items.length > 0 ? 'lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:gap-8' : ''}`}>
        <div className="min-w-0 space-y-1.5">
          <p data-testid="report-footer-note">
            {REPORT_NOTE[locale]}{' '}
            <button type="button" data-testid="report-footer-toggle" aria-expanded={open} onClick={() => setOpen((v) => !v)} className="font-medium text-text-secondary underline underline-offset-2 hover:text-text-primary">
              {open ? label.less : label.more}
            </button>
          </p>
          {open && <p data-testid="report-footer-more">{REPORT_NOTE_MORE[locale]}</p>}
        </div>
        {items.length > 0 && (
          <dl data-testid="report-footer-meta" className="grid min-w-0 grid-cols-2 gap-x-4 gap-y-2.5 border-t border-border/50 pt-3 sm:grid-cols-3 lg:border-s lg:border-t-0 lg:ps-6 lg:pt-0">
            {items.map(([itemLabel, value]) => (
              // The period is the one long value (two dates and an arrow): it takes two columns and wraps, never an ellipsis.
              <div key={itemLabel} className={`min-w-0 ${itemLabel === LABELS[locale].period ? 'col-span-2' : ''}`}>
                <dt className="text-[10px] tracking-wide">{itemLabel}</dt>
                <dd className="font-medium text-text-secondary [overflow-wrap:anywhere]"><bdi className="tnum">{value}</bdi></dd>
              </div>
            ))}
          </dl>
        )}
      </div>
    </footer>
  )
}
