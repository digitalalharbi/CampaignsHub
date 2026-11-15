import { Link } from 'react-router-dom'
import { usePortalPath } from '@/app/portalPath'
import { ApprovedBudgetBar, NoBudget } from '@/features/funding/ApprovedBudget'
import { FinancialDisclaimer } from '@/features/funding/FinancialDisclaimer'
import type { ClientDetail } from './api'

/**
 * ONE-BUDGET-001 — the client page's budget: each project's APPROVED budget (/agency/budgets), the same reading the home
 * dashboard, the campaigns page and the budgets page show. Remaining = deposits − settlements − converted spend, over
 * the budget's own period. A project with no budget says so and offers «أضف ميزانية»; a deposit's source (a payment in
 * the system, or a manual entry with its reference) is on the budget's own page, one press away.
 */
export function ClientBudgetCard({ budget, ar, clientName }: { budget: NonNullable<ClientDetail['budget']>; ar: boolean; clientName: string }) {
  const to = usePortalPath()
  if (budget.projects.length === 0) return null

  return (
    <section className="mt-4 flex flex-col gap-2 rounded-xl border border-border p-3" data-testid="client-budget-card" aria-label={ar ? 'الميزانية' : 'Budget'}>
      <h2 className="text-sm font-bold text-text-primary">{ar ? 'الميزانية' : 'Budget'}</h2>
      <ul className="flex flex-col gap-2">
        {budget.projects.map((p) => (
          <li key={p.project_id} className="flex min-w-0 flex-col gap-1" data-testid={`client-budget-${p.project_id}`}>
            {budget.projects.length > 1 && <span className="truncate text-xs font-semibold text-text-secondary">{p.project_name}</span>}
            {p.budget.has_budget ? (
              <Link to={to(`/budgets?project=${p.project_id}`)} className="block min-w-0 rounded-lg px-1 py-0.5 hover:bg-surface-hover">
                <ApprovedBudgetBar
                  ar={ar}
                  approved={p.budget.approved}
                  spent={p.budget.spent}
                  remaining={p.budget.remaining}
                  ratio={p.budget.used_ratio}
                  coverDays={p.budget.cover_days}
                  testid={`client-budget-bar-${p.project_id}`}
                />
              </Link>
            ) : (
              <NoBudget ar={ar} canAdd={budget.can_add} clientName={clientName} projectName={p.project_name} />
            )}
          </li>
        ))}
      </ul>
      <FinancialDisclaimer />
    </section>
  )
}
