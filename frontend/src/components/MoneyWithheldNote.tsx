import { ShieldOff } from 'lucide-react'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { useVisibility } from '@/stores/visibility'

/**
 * MEMBER-VISIBILITY-001 — the one sentence a member is owed when the money is withheld from them.
 *
 * Drawn only after a project-scoped answer said `money_withheld`, and only for the project in view,
 * so it never appears for a reader who sees everything and never names a project they did not open.
 */
export function MoneyWithheldNote() {
  const ar = useUi((s) => s.locale) === 'ar'
  const projectId = useProject((s) => s.currentProjectId)
  const withheld = useVisibility((s) => (projectId ? s.moneyWithheld[projectId] === true : false))
  if (!withheld) return null

  return (
    <p role="status" data-testid="money-withheld-note" className="mx-4 mt-3 flex items-center gap-2 rounded-xl border border-warning/40 bg-warning/10 px-3 py-2 text-xs text-text-secondary">
      <ShieldOff size={14} aria-hidden className="shrink-0 text-warning" />
      {ar
        ? 'الأرقام المالية لهذا المشروع (الإنفاق والإيراد والتكاليف) محجوبة عنك بقرار صلاحيات؛ ما تراه من نتائج صحيح وكامل، أما المال فلا يُعرض ولا يُصدَّر.'
        : 'The money on this project (spend, revenue, costs) is withheld from you by permission; the results you see are complete, the money is neither shown nor exported.'}
    </p>
  )
}
