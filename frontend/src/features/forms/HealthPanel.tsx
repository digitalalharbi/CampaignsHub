import { useState } from 'react'
import { Badge } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'
import { Skeleton } from '@/components/ui/States'
import { days as countedDays } from '@/lib/counted'
import { useUi } from '@/stores/ui'
import { useTrackingHealth } from './api'
import type { HealthSeverity } from './types'

/**
 * TRACKING-HEALTH-001 — whether this project's lead tracking can be trusted, and what to do where it cannot.
 *
 * Each row is a statement about something measured, with its numbers and the action it asks for. A check that could not be
 * made is drawn as «could not be checked», never as a pass. Coverage rates carry their denominator, a rate over too few leads is
 * a dash, and no accuracy percentage appears anywhere: the page says why it will not.
 */
const TONE: Record<HealthSeverity, 'danger' | 'warning' | 'info' | 'neutral' | 'success'> = { critical: 'danger', warning: 'warning', info: 'info', unavailable: 'neutral', ok: 'success' }
const PERIODS = [7, 30, 90] as const
const pct = (r: number | null) => (r === null ? '—' : `${Math.round(r * 100)}%`)

export function HealthPanel({ projectId }: { projectId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [days, setDays] = useState<(typeof PERIODS)[number]>(30)
  const q = useTrackingHealth(projectId, days)
  /* An answer without the expected shape is treated as no answer: a panel that throws would take the whole Forms page with it. */
  const h = q.data && Array.isArray(q.data.checks) && q.data.summary ? q.data : undefined
  const label: Record<HealthSeverity, string> = {
    critical: ar ? 'يحتاج إصلاحًا الآن' : 'Broken now', warning: ar ? 'يفقد بيانات' : 'Losing data', info: ar ? 'للعلم' : 'Worth knowing',
    unavailable: ar ? 'تعذّر الفحص' : 'Could not be checked', ok: ar ? 'سليم' : 'Fine',
  }

  return (
    <section className="space-y-3" data-testid="health-panel" aria-labelledby="health-title">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 id="health-title" className="text-lg font-bold text-text-primary">{ar ? 'سلامة التتبع' : 'Tracking health'}</h2>
          <p className="text-sm text-text-secondary">{ar ? 'هل يمكن الوثوق بتتبع العملاء المحتملين، وما المطلوب حيث لا يمكن.' : 'Whether lead tracking can be trusted, and what to do where it cannot.'}</p>
        </div>
        <div className="flex gap-1" role="group" aria-label={ar ? 'الفترة' : 'Period'}>
          {PERIODS.map((p) => (
            <button key={p} type="button" aria-pressed={days === p} onClick={() => setDays(p)} className={`rounded-lg border px-2 py-1 text-xs font-semibold ${days === p ? 'border-brand-500 text-brand-600' : 'border-border text-text-secondary'}`} data-testid={`health-period-${p}`}>{countedDays(p, ar ? 'ar' : 'en')}</button>
          ))}
        </div>
      </div>

      {q.isLoading && <Skeleton className="h-24" />}
      {q.isError && <p role="alert" className="text-sm text-danger">{ar ? 'تعذّر تحميل الفحص.' : 'The check could not be loaded.'}</p>}
      {h && (
        <Card className="space-y-4 p-4">
          <p className="text-sm font-semibold text-text-primary" data-testid="health-summary">
            {h.summary.critical === 0 && h.summary.warning === 0
              ? (ar ? 'لا شيء معطّل أو يفقد بيانات فيما أمكن فحصه.' : 'Nothing broken or losing data in what could be checked.')
              : (ar ? `${h.summary.critical} يحتاج إصلاحًا الآن · ${h.summary.warning} يفقد بيانات` : `${h.summary.critical} broken now · ${h.summary.warning} losing data`)}
          </p>

          <ul className="space-y-2" data-testid="health-checks">
            {h.checks.map((c) => (
              <li key={c.key} className="space-y-1 rounded-xl border border-border p-3" data-testid={`health-check-${c.key}`}>
                <div className="flex flex-wrap items-center gap-2">
                  <Badge tone={TONE[c.severity]}>{label[c.severity]}</Badge>
                  <span className="text-sm font-semibold text-text-primary">{ar ? c.title.ar : c.title.en}</span>
                </div>
                {c.detail && <p className="text-xs text-text-secondary">{ar ? c.detail.ar : c.detail.en}</p>}
                {c.action && <p className="text-xs text-text-primary"><strong>{ar ? 'المطلوب: ' : 'Do this: '}</strong>{ar ? c.action.ar : c.action.en}</p>}
              </li>
            ))}
          </ul>

          <div data-testid="health-coverage" className="space-y-1">
            <p className="text-sm font-bold text-text-primary">{ar ? 'التغطية' : 'Coverage'}</p>
            <p className="text-xs text-text-secondary">
              {ar ? 'من ' : 'Of '}<span data-testid="health-coverage-n">{h.coverage.form_leads}</span>{ar ? ' عميلًا محتملًا من النماذج: ' : ' form leads: '}
              {ar ? 'بوسم حملة ' : 'with a campaign tag '}{pct(h.coverage.with_campaign_tag)} · {ar ? 'بمعرّف نقرة ' : 'with a click id '}{pct(h.coverage.with_click_id)} · {ar ? 'بموافقة قياس ' : 'with measurement consent '}{pct(h.coverage.measurement_consent_granted)}
              {h.coverage.form_leads < h.coverage.minimum_for_a_rate && <em className="not-italic text-text-muted"> {ar ? `(أقل من ${h.coverage.minimum_for_a_rate}: لا نسبة)` : `(fewer than ${h.coverage.minimum_for_a_rate}: no rate)`}</em>}
            </p>
          </div>

          {h.deliveries.length > 0 && (
            <div className="overflow-x-auto" data-testid="health-deliveries">
              <table className="w-full min-w-[520px] text-xs"><thead className="text-text-muted"><tr><th className="p-1 text-start">{ar ? 'الوجهة' : 'Destination'}</th><th className="p-1 text-start">{ar ? 'مؤهَّل' : 'Eligible'}</th><th className="p-1 text-start">{ar ? 'حوولت' : 'Attempted'}</th><th className="p-1 text-start">{ar ? 'أُرسل' : 'Sent'}</th><th className="p-1 text-start">{ar ? 'فشل' : 'Failed'}</th><th className="p-1 text-start">{ar ? 'فرق' : 'Gap'}</th></tr></thead>
                <tbody>{h.deliveries.map((d) => <tr key={d.destination_id} className="border-t border-border"><td className="p-1">{d.name}</td><td className="p-1 tabular-nums">{d.eligible_leads}</td><td className="p-1 tabular-nums">{d.attempted}</td><td className="p-1 tabular-nums">{d.sent}</td><td className="p-1 tabular-nums">{d.failed}</td><td className="p-1 tabular-nums">{d.gap}</td></tr>)}</tbody></table>
            </div>
          )}

          {h.reconciliation.length > 0 && (
            <div className="overflow-x-auto" data-testid="health-reconciliation">
              <p className="mb-1 text-sm font-bold text-text-primary">{ar ? 'ما تُبلغ عنه المنصة مقابل ما في مركز العملاء المحتملين' : 'What the platform reports against what the Leads Centre holds'}</p>
              <table className="w-full min-w-[420px] text-xs"><thead className="text-text-muted"><tr><th className="p-1 text-start">{ar ? 'المنصة' : 'Platform'}</th><th className="p-1 text-start">{ar ? 'تُبلغ' : 'Reported'}</th><th className="p-1 text-start">{ar ? 'محفوظ' : 'Stored'}</th><th className="p-1 text-start">{ar ? 'الفرق' : 'Difference'}</th><th className="p-1 text-start">{ar ? 'محفوظ/مُبلَّغ' : 'Stored / reported'}</th></tr></thead>
                <tbody>{h.reconciliation.map((r) => <tr key={r.provider} className="border-t border-border"><td className="p-1">{r.provider}</td><td className="p-1 tabular-nums">{r.platform_reported}</td><td className="p-1 tabular-nums">{r.stored}</td><td className="p-1 tabular-nums">{r.difference}</td><td className="p-1 tabular-nums">{pct(r.stored_to_reported)}</td></tr>)}</tbody></table>
            </div>
          )}

          <p className="text-xs text-text-muted" data-testid="health-confidence">{ar ? h.confidence_note.ar : h.confidence_note.en}</p>
        </Card>
      )}
    </section>
  )
}
