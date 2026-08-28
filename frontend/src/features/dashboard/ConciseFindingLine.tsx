import { AlertTriangle } from 'lucide-react'
import { Link } from 'react-router-dom'

import { conciseFinding } from '@/features/analytics/conciseFinding'

/**
 * The dashboard's single diagnostic sentence.
 *
 * Renders NOTHING unless the shared engine produced a finding. Silence here is correct in three
 * different situations — the request has not answered, the scope holds no rows, and the account was
 * examined and is healthy — and none of them is a headline. A dashboard that prints a reassurance or
 * an explanation of an absence at the top of the page is making a claim it cannot support in the one
 * place a reader is least likely to question.
 */
const COPY: Record<string, { ar: string; en: string }> = {
  not_delivering: { ar: 'الحملات لا تُعرض رغم الإنفاق', en: 'Campaigns are not being delivered despite spend' },
  weak_attraction: { ar: 'الإعلانات تُعرض ولا تجذب الضغط', en: 'Ads are being seen and not clicked' },
  clicks_not_arriving: { ar: 'الضغطات لا تصل إلى الصفحة', en: 'Clicks are not arriving at the page' },
  visits_lost: { ar: 'جزء كبير من الضغطات يُفقد قبل الصفحة', en: 'A large share of clicks is lost before the page' },
  no_conversions: { ar: 'وصلت زيارات ولم يحدث تحويل', en: 'Visits arrived and nothing converted' },
  conversions_without_value: { ar: 'تحويلات بقيمة صفر', en: 'Conversions are recorded with no value' },
}

export function ConciseFindingLine({
  objective, totals, reported, rowsInScope, pending, ar,
}: {
  objective: string | null
  totals: Record<string, number | null | undefined> | undefined
  reported: Record<string, boolean> | undefined
  rowsInScope: boolean | undefined
  pending: boolean
  ar: boolean
}) {
  // An unanswered request and an empty scope have nothing to say, and saying so is the panel's job.
  if (pending || totals === undefined || reported === undefined || rowsInScope === false) {
    return null
  }

  const finding = conciseFinding({ objective, totals, reported })

  if (finding === null) {
    return null
  }

  const copy = COPY[finding.code]

  return (
    <div
      className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-surface px-3 py-2 text-sm"
      data-testid="dashboard-concise-finding"
    >
      <AlertTriangle size={16} className="shrink-0 text-warning" />
      <span className="text-text-primary">{copy ? (ar ? copy.ar : copy.en) : finding.code}</span>
      {/* An inference stays an inference on the headline too. */}
      {finding.confidence === 'probable' && (
        <span className="rounded-full border border-border px-2 py-0.5 text-[11px] text-text-muted" data-testid="dashboard-finding-probable">
          {ar ? 'مرجَّح' : 'Inferred'}
        </span>
      )}
      <Link to="/app/analytics?tab=performance" className="text-brand-600 hover:underline" data-testid="dashboard-finding-link">
        {ar ? 'التفاصيل' : 'Details'}
      </Link>
    </div>
  )
}
