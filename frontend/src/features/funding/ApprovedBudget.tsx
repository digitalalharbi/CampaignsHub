import { Link } from 'react-router-dom'
import { Plus, Wallet } from 'lucide-react'
import { usePortalPath } from '@/app/portalPath'
import { days as countedDays } from '@/lib/counted'
import type { ApprovedBudget } from './fundingApi'
import { formatMoney, Money } from './money'

/**
 * ONE-BUDGET-001 — the small pieces every surface uses to show the APPROVED project budget the same way.
 *
 * The figures come from the server (`ProjectBudgetReader`); nothing here computes one. A project without a budget says
 * «بدون ميزانية» and offers «أضف ميزانية» — it never shows a platform's campaign budget in its place.
 */
const T = {
  ar: { none: 'بدون ميزانية', add: 'أضف ميزانية', of: 'من', left: 'المتبقي', out: 'خلصت', cover: (n: number) => `تكفي ${countedDays(n, 'ar')}` },
  en: { none: 'No budget', add: 'Add budget', of: 'of', left: 'Remaining', out: 'Used up', cover: (n: number) => `${countedDays(n, 'en')} left` },
}

/** «بدون ميزانية» and, for someone who may add one, «أضف ميزانية» (opens the add dialog on the budgets page). */
export function NoBudget({ ar, canAdd, clientName, projectName, className = '' }: { ar: boolean; canAdd: boolean; clientName?: string; projectName?: string; className?: string }) {
  const t = ar ? T.ar : T.en
  const to = usePortalPath()

  return (
    <span className={`inline-flex flex-wrap items-center gap-2 text-xs ${className}`} data-testid="no-budget">
      <span className="font-semibold text-text-muted">{t.none}</span>
      {canAdd && (
        <Link
          to={to('/budgets?add=1')}
          state={{ clientName: clientName ?? '', projectName: projectName ?? '' }}
          data-testid="no-budget-add"
          className="inline-flex items-center gap-1 rounded-md border border-border px-2 py-0.5 font-semibold text-brand-600 hover:border-brand-400"
          onClick={(e) => e.stopPropagation()}
        >
          <Plus size={12} aria-hidden /> {t.add}
        </Link>
      )}
    </span>
  )
}

/** «X من Y» — spent of approved, both in SAR. */
export function SpentOfApproved({ ar, spent, approved, className = '' }: { ar: boolean; spent: number | null; approved: number | null; className?: string }) {
  const t = ar ? T.ar : T.en

  return (
    <span className={`tnum ${className}`} data-testid="spent-of-approved">
      <Money n={spent} currency="SAR" /> {t.of} <Money n={approved} currency="SAR" />
    </span>
  )
}

/** «تكفي X يومًا» from the server's cover days; nothing when it cannot be measured. */
export function coverLabel(ar: boolean, coverDays: number | null): string | null {
  const t = ar ? T.ar : T.en
  if (coverDays === null) return null

  return coverDays === 0 ? t.out : t.cover(coverDays)
}

/** One compact row: a bar of the used share, spent of approved, remaining and cover days. */
export function ApprovedBudgetBar({ ar, approved, spent, remaining, ratio, coverDays, testid = 'approved-budget' }: {
  ar: boolean
  approved: number | null
  spent: number | null
  remaining: number | null
  ratio: number | null
  coverDays: number | null
  testid?: string
}) {
  const t = ar ? T.ar : T.en
  const pct = ratio === null ? 0 : Math.max(0, Math.min(1, ratio))
  const tone = ratio === null ? 'bg-brand-600' : ratio >= 1 ? 'bg-danger' : ratio >= 0.9 ? 'bg-warning' : 'bg-brand-600'
  const cover = coverLabel(ar, coverDays)

  return (
    <div className="flex min-w-0 flex-col gap-1" data-testid={testid}>
      <div className="flex min-w-0 flex-wrap items-center justify-between gap-x-3 gap-y-0.5 text-xs">
        <span className="inline-flex min-w-0 items-center gap-1.5 text-text-secondary">
          <Wallet size={13} aria-hidden className="shrink-0" />
          <SpentOfApproved ar={ar} spent={spent} approved={approved} />
        </span>
        <span className="font-semibold text-text-primary" data-testid={`${testid}-remaining`}>
          {t.left} <Money n={remaining} currency="SAR" />
          {cover && <span className="ms-2 font-normal text-text-muted">{cover}</span>}
        </span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
        <div className={`h-full rounded-full ${tone}`} style={{ width: `${pct * 100}%` }} />
      </div>
    </div>
  )
}

/**
 * ONE-BUDGET-001 — the campaigns page's «ضمن الميزانية — متوقع X من Y», against the APPROVED budget and its own period.
 *
 * The forecast is the server's (`projected`: spend so far + the last three complete days' rate to the period's last day);
 * an open-ended period has no forecast and reads «المتبقي X من Y» instead. No budget → «بدون ميزانية» + «أضف ميزانية».
 * The test ids are the ones the page always carried, so the overview's checks keep meaning the same thing.
 */
export function ApprovedBudgetPacing({ approved, ar, canAdd }: { approved: ApprovedBudget; ar: boolean; canAdd: boolean }) {
  if (!approved.has_budget) {
    if (approved.withheld) return null

    return (
      <p className="text-xs text-text-muted" data-testid="budget-pacing-unavailable">
        <NoBudget ar={ar} canAdd={canAdd} />
      </p>
    )
  }

  const { approved: total, spent, remaining, projected, used_ratio: ratio, cover_days: cover } = approved
  const over = projected !== null && projected > total
  const sentence = projected !== null
    ? over
      ? (ar ? `بهذا المعدل سيتجاوز الإنفاق الميزانية بـ ${formatMoney(projected - total)}` : `At this rate spend will exceed the budget by ${formatMoney(projected - total)}`)
      : (ar ? `ضمن الميزانية — متوقع ${formatMoney(projected)} من ${formatMoney(total)}` : `Within budget — forecast ${formatMoney(projected)} of ${formatMoney(total)}`)
    : (ar ? `المتبقي ${formatMoney(remaining)} من ${formatMoney(total)}` : `Remaining ${formatMoney(remaining)} of ${formatMoney(total)}`)
  const coverText = coverLabel(ar, cover)

  return (
    <div className="flex flex-col gap-2 rounded-2xl border border-border bg-surface p-4" data-testid="budget-pacing">
      <div className="h-2 overflow-hidden rounded-full bg-surface-secondary" aria-hidden>
        <div className={`h-full rounded-full ${over || (ratio ?? 0) >= 1 ? 'bg-danger' : 'bg-brand-600'}`} style={{ width: `${Math.min(100, (ratio ?? 0) * 100)}%` }} />
      </div>
      <span className={`text-xs font-semibold ${over ? 'text-danger' : 'text-text-secondary'}`} data-testid="budget-pacing-reading">
        {sentence}
        {coverText && <span className="ms-2 font-normal text-text-muted">{coverText}</span>}
      </span>
      <span className="text-[11px] text-text-muted" data-testid="budget-pacing-spent">
        <SpentOfApproved ar={ar} spent={spent} approved={total} />
      </span>
    </div>
  )
}
