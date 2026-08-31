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
    confidence: 'الثقة',
    previousPeriod: 'الفترة السابقة',
    aiReview: 'مولَّد آليًا — يحتاج مراجعة بشرية',
    openCreative: 'فتح الإعلان',
  },
  en: {
    action: 'Suggested action',
    confidence: 'Confidence',
    previousPeriod: 'Previous period',
    aiReview: 'Generated — needs human review',
    openCreative: 'Open ad',
  },
} as const

export function CreativeInsightCard({
  item,
  locale,
  /** Where this finding's creative lives, when the surface can link to it. Absent on the page itself. */
  creativeHref,
}: {
  item: CreativeInsight
  locale: Locale
  creativeHref?: string | null
}) {
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']
  const action = ar ? item.action_ar : item.action_en

  return (
    <li className={`rounded-md border p-3 ${SEVERITY_TONE[item.severity] ?? 'border-border'}`}>
      <p className="text-sm font-semibold text-text-primary">{ar ? item.title_ar : item.title_en}</p>
      <p className="mt-1 text-sm text-text-secondary">{ar ? item.detail_ar : item.detail_en}</p>
      {action && (
        <p className="mt-2 text-sm text-text-primary">
          <span className="font-medium">{t.action}:</span> {action}
        </p>
      )}
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
    </li>
  )
}
