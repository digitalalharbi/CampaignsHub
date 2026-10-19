import { useState } from 'react'
import { objectiveLabel } from '@/features/campaigns/labels'
import { platformLabel, sortPlatforms } from '@/lib/platforms'

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

/**
 * AGENTS-REPORT-NOTES-BLOCK-001 — what every report that SHOWS SPEND says about its money: where the
 * figures came from and when, that the platforms' invoices are the reference, and that conversion moves
 * them. A report that shows no spend (the default for a client link) carries none of it, so the notes
 * never hint at money the reader was not shown.
 */
export const SPEND_NOTES = {
  ar: 'الأرقام كما أبلغت عنها المنصات وقت قراءتها، والفواتير الرسمية الصادرة من المنصات هي المرجع، وقد تظهر فروق بسبب تحويل العملات.',
  en: "Figures are the platforms' own at the time they were read; the platforms' official invoices are the reference, and currency conversion can cause differences.",
} as const

const SOURCES_LABEL = { ar: 'مصادر البيانات', en: 'Data sources' } as const

/** The notes as one line, for surfaces that print rather than render a footer (the print document). */
export function spendNotesLine(sources: readonly string[], locale: 'ar' | 'en'): string {
  const named = sortPlatforms(Array.from(new Set(sources.filter(Boolean)))).map((p) => platformLabel(p, locale === 'ar'))
  return named.length > 0
    ? `${SPEND_NOTES[locale]} ${SOURCES_LABEL[locale]}: ${named.join(locale === 'ar' ? '، ' : ', ')}.`
    : SPEND_NOTES[locale]
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
  /** Set only when the report shows spend: the notes block appears, naming these platforms as sources. */
  spendSources?: string[] | null
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

  return (
    <footer data-testid="report-footer" dir={locale === 'ar' ? 'rtl' : 'ltr'} className={`report-footer mx-auto mt-8 max-w-3xl rounded-xl border border-border/60 px-4 py-3 text-center text-[11px] leading-relaxed text-text-muted ${className}`}>
      <p data-testid="report-footer-note">
        {REPORT_NOTE[locale]}{' '}
        <button type="button" data-testid="report-footer-toggle" aria-expanded={open} onClick={() => setOpen((v) => !v)} className="font-medium text-text-secondary underline underline-offset-2 hover:text-text-primary">
          {open ? label.less : label.more}
        </button>
      </p>
      {open && <p data-testid="report-footer-more" className="mt-1.5">{REPORT_NOTE_MORE[locale]}</p>}
      {facts.spendSources && (
        <p data-testid="report-footer-spend-notes" className="mt-1.5">
          {spendNotesLine(facts.spendSources, locale)}
        </p>
      )}
      {items.length > 0 && (
        <p data-testid="report-footer-meta" className="mt-2 flex flex-wrap items-center justify-center gap-y-1 border-t border-border/50 pt-2">
          {items.map(([itemLabel, value], i) => (
            <span key={itemLabel} className="inline-flex items-center">
              {i > 0 && <span aria-hidden className="mx-2.5 h-3 w-px bg-border" />}
              <span>{itemLabel}: <bdi className="tnum">{value}</bdi></span>
            </span>
          ))}
        </p>
      )}
    </footer>
  )
}
