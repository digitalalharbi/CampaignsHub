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
  ar: 'كل جهة قياس ترى جزءًا من الرحلة: المنصات الإعلانية، والمتجر أو الموقع، والمؤثرون، وكل قناة أخرى. لذلك من الطبيعي أن يختلف الرقم من مصدر إلى آخر، فلكل مصدر طريقة احتساب ونافذة إسناد وتوقيت تحديث خاص به، وتُقرأ النتائج في ضوء الصورة الكاملة لا من رقم واحد منفرد. تُدار الحملات وفق منهجية قائمة على الأداء والبيانات المتاحة، وتتأثر النتائج بعوامل منها: الفترة الزمنية ومرحلة تعلّم الحملة، ونموذج الإسناد ونوافذه ومصدر البيانات، وهدف الحملة وطبيعة المنتج والتسعير، والعروض والخصومات المقدمة، وجودة المحتوى والجمهور والميزانية، وسلوك العملاء ومستوى المنافسة، وتكاليف المنصات الإعلانية ومعايير الأداء الخاصة بكل منصة. وقد تتغير بعض الأرقام بعد اكتمال معالجة المنصات للبيانات أو بسبب التحويلات المتأخرة؛ لذلك قد تختلف النتائج من فترة إلى أخرى بحسب هذه المتغيرات، ولا تمثل المؤشرات الحالية ضمانًا لنتائج مستقبلية ثابتة.',
  en: 'Each measurement source sees only part of the journey: the advertising platforms, the store or website, influencers, and every other channel. It is therefore normal for a figure to differ from one source to another, because each source has its own way of counting, its own attribution window and its own update timing, and results are read in light of the full picture rather than from a single figure. Campaigns are managed on a performance-based methodology using the data available, and results are affected by factors including: the time period and the campaign learning phase; the attribution model, its windows and the data source; the campaign objective, the nature of the product and its pricing; the offers and discounts provided; the quality of the content, audience and budget; customer behaviour and the level of competition; and advertising platform costs and each platform\'s own performance standards. Some figures may change after the platforms finish processing data or because of late conversions; results may therefore differ from one period to another depending on these variables, and current indicators do not guarantee consistent future results.',
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
  ar: { updated: 'آخر تحديث للتقرير', period: 'الفترة', currency: 'العملة', timezone: 'المنطقة الزمنية', objective: 'هدف الحملة' },
  en: { updated: 'Report last updated', period: 'Period', currency: 'Currency', timezone: 'Time zone', objective: 'Campaign objective' },
} as const

/** The metadata line's items, in the Owner's order; an item with no value is left out, never printed as «—». */
export function reportFooterItems(facts: ReportFooterFacts, locale: 'ar' | 'en'): Array<[string, string]> {
  const t = LABELS[locale]
  const items: Array<[string, string | null]> = [
    [t.updated, facts.updatedAt ? formatStamp(facts.updatedAt, facts.timezone) : null],
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
