import { Link } from 'react-router-dom'
import { useSpendLimits, type SpendLimitReading } from '@/features/budget/spendLimitsApi'
import { providerLabel } from '@/features/campaigns/labels'
import { Money } from './money'
import { useProject } from '@/stores/project'

const SCOPE: Record<string, { ar: string; en: string }> = {
  project: { ar: 'المشروع', en: 'Project' },
  platform: { ar: 'منصة', en: 'Platform' },
  account: { ar: 'حساب', en: 'Account' },
  campaign: { ar: 'حملة', en: 'Campaign' },
}

const STATE_BAR: Record<SpendLimitReading['state'], string> = {
  ok: 'bg-success',
  approaching: 'bg-warning',
  over: 'bg-danger',
  unknown: 'bg-border-strong',
}

/**
 * ONE-BUDGET-UI-001 — the budget IS the project's spend limit, drawn inside the budget.
 *
 * The server has linked every limit to its project's approved budget since ONE-BUDGET-001: the budget announces its own
 * alert steps (50 · 75 · 90 · 100 unless it states others) against the platforms' actual spend, and a platform, account or
 * campaign limit is a finer threshold of it. The rail still showed «حدود الإنفاق» as a second page beside «الميزانيات»,
 * so the same money read as two things. Here, in the budget's own panel: the steps — a filled step is one the spend has
 * already crossed — and the finer limits, each with its own share used. Every figure is the server's; nothing is derived.
 */
export function BudgetLimits({ projectId, usedRatio, ar }: { projectId: string; usedRatio: number | null; ar: boolean }) {
  const q = useSpendLimits(projectId)
  const choose = useProject((s) => s.setCurrentProjectId)
  const budget = q.data?.budget
  const thresholds = budget && budget.has_budget ? budget.thresholds : []
  const finer = (q.data?.limits ?? []).filter((l) => typeof l?.id === 'string' && l.scope !== 'project' && l.scope in SCOPE)
  const steps = thresholds.length > 0 ? thresholds : [50, 75, 90, 100]
  const used = usedRatio === null ? null : usedRatio * 100

  return (
    <section data-testid="budget-limits" className="flex min-w-0 flex-col gap-2 rounded-xl border border-border bg-surface-secondary/40 p-3">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-xs font-bold text-text-secondary">{ar ? 'حد الإنفاق التلقائي' : 'Automatic spend limit'}</h3>
        <Link to="/agency/spend-limits" onClick={() => choose(projectId)} data-testid="budget-limits-manage" className="text-xs font-semibold text-brand-700 hover:underline">
          {ar ? 'حدود أدق' : 'Finer limits'}
        </Link>
      </div>

      <ol className="flex flex-wrap items-center gap-1.5" data-testid="budget-limit-steps" aria-label={ar ? 'خطوات التنبيه' : 'Alert steps'}>
        {steps.map((s) => {
          const crossed = used !== null && used >= s
          return (
            <li
              key={s}
              data-crossed={crossed || undefined}
              className={`tnum rounded-[var(--radius-pill)] border px-2 py-0.5 text-xs font-bold ${crossed ? (s >= 100 ? 'border-danger bg-danger/10 text-danger' : 'border-warning bg-warning/10 text-warning') : 'border-border text-text-muted'}`}
            >
              <bdi dir="ltr">{s}%</bdi>
            </li>
          )
        })}
      </ol>

      {finer.length > 0 && (
        <ul className="flex min-w-0 flex-col gap-1.5" data-testid="budget-finer-limits">
          {finer.map((l) => {
            const scope = SCOPE[l.scope] ?? SCOPE.project!
            const name = l.scope === 'platform' && l.scope_id ? providerLabel(l.scope_id, ar ? 'ar' : 'en') : (ar ? scope.ar : scope.en)
            const share = l.utilisation === null ? null : Math.round(l.utilisation * 100)

            return (
              <li key={l.id} className="flex min-w-0 items-center gap-2 text-xs" data-testid={`budget-finer-${l.id}`}>
                <span className="w-20 shrink-0 truncate font-semibold text-text-primary">{name}</span>
                <span className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
                  <span className={`block h-full rounded-full ${STATE_BAR[l.state]}`} style={{ width: `${Math.min(100, share ?? 0)}%` }} />
                </span>
                <bdi dir="ltr" className="tnum w-10 shrink-0 text-end font-bold text-text-primary">{share === null ? '—' : `${share}%`}</bdi>
                <span className="shrink-0 text-text-muted"><Money n={l.amount} currency={l.currency} /></span>
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}
