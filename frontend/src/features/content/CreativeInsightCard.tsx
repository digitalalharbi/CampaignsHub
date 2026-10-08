import { Link } from 'react-router-dom'
import type { CreativeInsight } from './api'
import type { Locale } from '@/stores/ui'

/**
 * §15.10 — one finding, rendered the same way wherever it is read.
 *
 * The dashboard section and the creative detail page are fed by the SAME `CreativeInsights` engine,
 * and until this component existed only one of them drew what it was given: `GET /creatives/pulse`
 * returned `insights` and the dashboard never rendered them. Two renderings would have been the same
 * defect one step later — the operator's dashboard describing a finding in different words from the
 * page it links into.
 *
 * Every field here is EVIDENCE, not decoration:
 *
 *   - the confidence, so a finding that fired on thin data cannot be read as a settled one;
 *   - both windows, so «down 40%» always says «against what»;
 *   - `needs_human_review`, declared whenever a model wrote the sentence. A generated finding must
 *     never reach a decision undeclared, which is why the flag is on the item rather than on a
 *     feature switch somebody could forget to read.
 */

const SEVERITY_TONE: Record<string, string> = {
  warning: 'border-danger/40 bg-danger/5',
  opportunity: 'border-primary/40 bg-primary/5',
  positive: 'border-success/40 bg-success/5',
}

const CONFIDENCE_LABEL: Record<string, { ar: string; en: string }> = {
  high: { ar: 'ثقة عالية', en: 'High confidence' },
  medium: { ar: 'ثقة متوسطة', en: 'Medium confidence' },
  // Never «low»: «we could not tell» is a different statement from «we are not very sure».
  insufficient_data: { ar: 'بيانات غير كافية', en: 'Insufficient data' },
}

const COPY = {
  ar: {
    action: 'الإجراء المقترح',
    why: 'التفاصيل والإجراء',
    confidence: 'الثقة',
    previousPeriod: 'الفترة السابقة',
    aiReview: 'مولَّد آليًا — يحتاج مراجعة بشرية',
    openCreative: 'فتح الإعلان',
  },
  en: {
    action: 'Suggested action',
    why: 'Detail and action',
    confidence: 'Confidence',
    previousPeriod: 'Previous period',
    aiReview: 'Generated — needs human review',
    openCreative: 'Open ad',
  },
} as const

/**
 * «7 محتوى آخر» is not Arabic. The number decides the noun, so the noun follows the number.
 *
 * Two is the dual, three to ten take the plural, and eleven upwards return to the singular. A count
 * printed with one fixed form is wrong for most of the range it can hold, and this line exists
 * precisely because a reader is counting.
 */
function alsoCount(n: number): string {
  if (n === 1) return 'محتوى آخر'
  if (n === 2) return 'محتويين آخرين'

  return n <= 10 ? `${n} محتويات أخرى` : `${n} محتوى آخر`
}

export function CreativeInsightCard({
  item,
  locale,
  /** Where this finding's creative lives, when the surface can link to it. Absent on the page itself. */
  creativeHref,
  alsoNamed,
}: {
  item: CreativeInsight
  locale: Locale
  creativeHref?: string | null
  /**
   * The OTHER creatives this identical finding was raised for.
   *
   * A rule that fires on many creatives produced one card each, and when their figures matched
   * exactly — which happens whenever several ads share a name and a budget — the cards were
   * byte-identical. Seven of them in a row on the demo tenant's dashboard, same title, same
   * sentence, same dates. The finding is worth reading once; WHICH creatives it covers is the part
   * that differs, so that is the part the card names.
   */
  alsoNamed?: string[]
}) {
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']
  const action = ar ? item.action_ar : item.action_en

  return (
    <li
      /*
       * How many findings this card accounts for beyond its own, as a number rather than a
       * sentence. The panel's counter reports the findings the SERVER shows, and grouping means one
       * card can stand for several — so anything checking that nothing was dropped has to be able
       * to add them up without parsing «وينطبق أيضًا على 7 محتويات أخرى».
       */
      data-also-count={alsoNamed?.length ?? 0}
      className={`rounded-md border p-3 ${SEVERITY_TONE[item.severity] ?? 'border-border'}`}
    >
      {/*
        UI-FINDING-DENSITY-001 — the finding leads; its account opens on demand.

        Thirteen of these stack on the dashboard, and each printed three paragraphs: the title, the
        detail, and the suggested action. Measured on the demo tenant that is 2,330 characters of
        prose against two charts on the first screen an operator opens — «حاول تقليل الجانب النصي …
        وليست نصية فقط».

        Nothing is removed. The title and the severity are what a reader scans, so they stay; the
        detail and the action are what they read once they have chosen a finding, so they sit behind
        a disclosure. The evidence line stays out too, because a confidence and a window are what
        stop a finding being read as settled, and burying those would be hiding the caveat rather
        than the explanation.

        `<details>` rather than React state: it is keyboard-operable, it is findable by the browser's
        own in-page search, and its content stays in the DOM — so anything that asserts the detail or
        the action is present still finds it.
      */}
      <p className="text-sm font-semibold text-text-primary">{ar ? item.title_ar : item.title_en}</p>
      <details className="group mt-1">
        <summary className="cursor-pointer list-none text-xs font-medium text-text-secondary hover:text-text-primary">
          <span className="underline decoration-dotted underline-offset-2">{t.why}</span>
        </summary>
        {/*
          Collapsed EXPLICITLY, not by the user agent.

          Measured in the browser: with `open` false the paragraphs still had boxes 21.7px tall, so
          the wall of text was unchanged and only a summary had been added on top of it. Something
          in this application's CSS out-specifies the UA rule that hides a closed `details`' content,
          so the collapse is stated here instead of assumed — `group-open:` reads the same element's
          own state, which keeps `<details>` doing the work and the markup semantic.
        */}
        <div className="hidden group-open:block">
          <p className="mt-1.5 text-sm text-text-secondary">{ar ? item.detail_ar : item.detail_en}</p>
          {action && (
            <p className="mt-2 text-sm text-text-primary">
              <span className="font-medium">{t.action}:</span> {action}
            </p>
          )}
        </div>
      </details>
      <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-text-secondary">
        <span>
          {t.confidence}: {CONFIDENCE_LABEL[item.confidence]?.[ar ? 'ar' : 'en'] ?? item.confidence}
        </span>
        <span dir="ltr">
          {item.period.from} → {item.period.to}
        </span>
        <span dir="ltr">
          {t.previousPeriod}: {item.previous_period.from} → {item.previous_period.to}
        </span>
        {item.needs_human_review && (
          <span className="rounded bg-warning/15 px-1.5 py-0.5 text-warning">{t.aiReview}</span>
        )}
        {creativeHref && item.creative_id && (
          <Link to={creativeHref} className="text-primary underline">
            {t.openCreative}
            {item.creative_name ? `: ${item.creative_name}` : ''}
          </Link>
        )}
      </p>
      {alsoNamed !== undefined && alsoNamed.length > 0 && (
        /*
         * The COUNT leads, and the names are de-duplicated.
         *
         * A first version listed every name and produced «Teaser، Teaser، Teaser، Teaser، Teaser،
         * Teaser، Teaser» — the identical-card defect moved into one line, because the creatives
         * that share a finding this exactly are usually the ones that share a name. What a reader
         * needs is how many, and then whatever names actually distinguish them.
         */
        <p data-testid="insight-also-named" className="mt-1 text-[11px] text-text-secondary">
          {ar ? `وينطبق أيضًا على ${alsoCount(alsoNamed.length)}: ` : `Also applies to ${alsoNamed.length} more: `}
          <span className="font-medium text-text-primary">
            {[...new Set(alsoNamed)].join(ar ? '، ' : ', ')}
          </span>
        </p>
      )}
    </li>
  )
}
