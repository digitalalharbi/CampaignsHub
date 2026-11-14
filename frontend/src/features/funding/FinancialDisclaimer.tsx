import { Info } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useFinancialDisclaimer } from './fundingApi'

/**
 * BUDGET-DISCLAIMER-001 — the one financial disclaimer, ONE line, never a modal.
 *
 * The words are NOT in this file: they come from the server (`GET /funding/disclaimer`, the same text the PDF, the sheets,
 * the notices and the spend-summary mail carry), so no surface can restate or soften them. Shown on every screen that
 * puts ad spend, budgets, balances or converted money in front of a team member; never on a client's shared report.
 * While the text is not loaded nothing is drawn: a placeholder would be a second wording.
 */
export function FinancialDisclaimer({ className = '' }: { className?: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const { data } = useFinancialDisclaimer()
  const text = data ? (ar ? data.ar : data.en) : null
  if (!text) return null

  return (
    <p data-testid="financial-disclaimer" className={`flex min-w-0 items-start gap-1.5 text-xs leading-relaxed text-text-muted ${className}`}>
      <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden />
      <span className="min-w-0">{text}</span>
    </p>
  )
}
