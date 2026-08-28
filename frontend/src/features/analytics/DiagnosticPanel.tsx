import { AlertTriangle, CheckCircle2, HelpCircle } from 'lucide-react'

import { QueryFailure } from '@/components/ui/QueryFailure'
import { metricLabel } from '@/features/analytics/metricLabels'

import { diagnose, type DiagnosticFinding, type DiagnosticStage } from './diagnose'
import { Panel } from './components'

/**
 * ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — the reasoning layer, rendered.
 *
 * `diagnose()` shipped with tests and no reader: a reasoning layer nothing asked a question of. This
 * is the surface that asks, and it is deliberately the ONLY one — the same function the dashboard and
 * the campaigns workspace will call, never a second engine that would eventually disagree with it
 * about the same account.
 *
 * ## The four answers, kept apart
 *
 * The whole point of the layer is that these are different statements, and the copy may never let one
 * read as another:
 *
 *   - **a weakness, found** — findings, each with the evidence it was derived from;
 *   - **examined, nothing found** — a clean bill of health, which the reader is entitled to;
 *   - **could not be examined** — the chain had no reported evidence to read;
 *   - **nothing to examine** — no spend in this scope, so there is no weakness to locate.
 *
 * «Could not be examined» rendered as «no problems found» is the failure this requirement exists to
 * prevent, and it is the cheapest one to commit by accident: both are an empty findings list.
 */

/** What each finding says, and — for an inference — that it IS one. */
const COPY: Record<string, { ar: string; en: string }> = {
  not_delivering: {
    ar: 'الحملة لا تُعرض. أُنفق المال ولم تُسجَّل أي مرة ظهور.',
    en: 'Nothing is being delivered. Money was spent and no impression was recorded.',
  },
  weak_attraction: {
    ar: 'الإعلان يُعرض ولا يجذب الضغط. المرجَّح أن الرسالة أو الجمهور غير مناسب.',
    en: 'It is being seen and not clicked. The message or the audience is the likely cause.',
  },
  clicks_not_arriving: {
    ar: 'هناك ضغطات ولم تصل أي زيارة. هذا يشير إلى الرابط أو القياس، لا إلى الصفحة.',
    en: 'There are clicks and no arrivals. That points at the link or the tracking, not the page.',
  },
  visits_lost: {
    ar: 'جزء كبير من الضغطات لا يصل إلى الصفحة. المرجَّح بطء التحميل أو تحويل الرابط.',
    en: 'A large share of clicks never arrives. Load time or a redirect is the likely cause.',
  },
  no_conversions: {
    ar: 'وصلت زيارات ولم يحدث أي تحويل.',
    en: 'Visits arrived and nothing converted.',
  },
  conversions_without_value: {
    ar: 'هناك تحويلات بقيمة صفر. غالبًا لم تُرسل قيمة الشراء، لا أن الشراء بلا قيمة.',
    en: 'Conversions carry no value. Usually the value was never sent, not that it was zero.',
  },
}

const STAGE: Record<DiagnosticStage, { ar: string; en: string }> = {
  delivery: { ar: 'الوصول', en: 'Delivery' },
  attraction: { ar: 'الاهتمام', en: 'Attraction' },
  visit: { ar: 'الزيارة', en: 'Visit' },
  conversion: { ar: 'التحويل', en: 'Conversion' },
  value: { ar: 'القيمة', en: 'Value' },
}

export function DiagnosticPanel({
  objective,
  totals,
  reported,
  rowsInScope,
  loading,
  error,
  onRetry,
  ar,
}: {
  objective: string | null
  totals: Record<string, number | null | undefined> | undefined
  reported: Record<string, boolean> | undefined
  rowsInScope: boolean | undefined
  loading?: boolean
  error?: unknown
  onRetry?: () => void
  ar: boolean
}) {
  const title = ar ? 'أين الضعف' : 'Where the weakness is'

  /*
   * Failure outranks every other arm. A panel that cannot read the totals knows nothing about this
   * account, and «no problems found» drawn over a failed request is a claim made from no data at all.
   */
  if (error) {
    return (
      <Panel title={title}>
        <QueryFailure error={error} ar={ar} onRetry={onRetry} fallbackTitle={title} testId="diagnostic-failure" />
      </Panel>
    )
  }

  if (loading || totals === undefined || reported === undefined) {
    return (
      <Panel title={title} loading>
        <div className="h-20 animate-pulse rounded-xl bg-surface-muted" data-testid="diagnostic-loading" />
      </Panel>
    )
  }

  /*
   * An empty scope has no standing to say anything about a campaign — the same rule the metric strip
   * follows. Every metric reads unreported over no rows, which would otherwise be diagnosed as «not
   * delivering»: a claim about the platform, derived from a filter.
   */
  if (rowsInScope === false) {
    return (
      <Panel title={title}>
        <Note
          testId="diagnostic-empty-scope"
          icon={<HelpCircle size={18} />}
          text={ar
            ? 'لا توجد حملات ضمن هذا التصفية. لا شيء يُفحص — والسبب هو التصفية، لا المنصة.'
            : 'No campaigns match this filter. There is nothing to examine, and the filter is why — not the platform.'}
        />
      </Panel>
    )
  }

  const d = diagnose({ objective, totals, reported })

  return (
    <Panel title={title} description={ar ? 'يُقرأ على مراحل الرحلة، ولا يُدّعى سبب بلا دليله' : 'Read along the journey, and no cause is claimed without its evidence'}>
      {d.state === 'not_diagnosable' ? (
        <Note
          testId="diagnostic-not-diagnosable"
          icon={<HelpCircle size={18} />}
          text={ar
            ? 'لا يمكن الفحص: لم تُرسل المنصات ما يكفي من القياسات في هذه الفترة. هذا ليس «لا توجد مشاكل».'
            : 'Cannot be examined: the platforms did not report enough in this period. This is not «no problems found».'}
        />
      ) : d.findings.length === 0 ? (
        <Note
          testId="diagnostic-healthy"
          tone="good"
          icon={<CheckCircle2 size={18} />}
          text={ar
            ? 'فُحصت كل مرحلة تتوفر لها قياسات ولم يظهر ضعف.'
            : 'Every stage with reported measurements was examined, and no weakness showed.'}
        />
      ) : (
        <ul className="flex flex-col gap-3" data-testid="diagnostic-findings">
          {d.findings.map((f) => (
            <Finding key={f.code} finding={f} ar={ar} />
          ))}
        </ul>
      )}

      {/*
        * Named, never skipped. What could not be examined is part of the answer: a reader who is not
        * told that «visit» went unread will take the absence of a visit finding as a healthy visit.
        */}
      {d.missing.length > 0 && (
        <p className="mt-4 border-t border-border pt-3 text-xs text-text-muted" data-testid="diagnostic-missing">
          {ar
            ? `لم تُفحص مراحل تعتمد على قياسات لم ترسلها المنصات: ${d.missing.map((k) => metricLabel(k, true)).join('، ')}. الفراغ ليس صفرًا.`
            : `Stages depending on these unreported measurements were not examined: ${d.missing.map((k) => metricLabel(k, false)).join(', ')}. A gap is not a zero.`}
        </p>
      )}
    </Panel>
  )
}

function Finding({ finding, ar }: { finding: DiagnosticFinding; ar: boolean }) {
  const copy = COPY[finding.code]

  return (
    <li className="flex gap-3 rounded-xl border border-border bg-surface-muted/40 p-3" data-testid={`diagnostic-finding-${finding.code}`}>
      <AlertTriangle size={18} className="mt-0.5 shrink-0 text-warning" />
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-semibold text-text-primary">{ar ? STAGE[finding.stage].ar : STAGE[finding.stage].en}</span>
          {/*
            * A ratio does not observe a cause, it suggests one. The distinction is carried in the data
            * as `confidence` and it has to survive into the copy, or the reader acts on an inference
            * believing it was a measurement.
            */}
          <span
            className="rounded-full border border-border px-2 py-0.5 text-[11px] text-text-muted"
            data-testid={`diagnostic-confidence-${finding.confidence}`}
          >
            {finding.confidence === 'observed' ? (ar ? 'مقيس' : 'Measured') : ar ? 'مرجَّح' : 'Inferred'}
          </span>
        </div>
        <p className="mt-1 text-sm text-text-secondary">{copy ? (ar ? copy.ar : copy.en) : finding.code}</p>
        <p className="mt-1 text-xs text-text-muted">
          {(ar ? 'مبني على: ' : 'From: ') + finding.evidence.map((k) => metricLabel(k, ar)).join(ar ? '، ' : ', ')}
        </p>
      </div>
    </li>
  )
}

function Note({ text, icon, testId, tone }: { text: string; icon: React.ReactNode; testId: string; tone?: 'good' }) {
  return (
    <div
      className={`flex items-start gap-2 rounded-xl border border-border p-3 text-sm ${tone === 'good' ? 'text-text-secondary' : 'text-text-muted'}`}
      data-testid={testId}
    >
      <span className={`mt-0.5 shrink-0 ${tone === 'good' ? 'text-success' : 'text-text-muted'}`}>{icon}</span>
      <span>{text}</span>
    </div>
  )
}
