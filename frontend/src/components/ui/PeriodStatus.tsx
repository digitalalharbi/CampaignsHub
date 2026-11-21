import { useId, useState } from 'react'
import { Info } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { platformLabel } from '@/lib/platforms'
import { formatDay, type Period } from '@/lib/period/periodMath'
import { usePeriodCoverage, type PeriodCoverage } from '@/lib/period/coverageApi'
import { Num } from '@/components/ui/Num'
import { days as countedDays } from '@/lib/counted'

/**
 * DATE-RANGE-001 §4 — the two honest badges a period can earn.
 *
 *  - «جاري — غير مكتمل»: the period contains today. Today's figures are still arriving, so a total
 *    that includes it is not final and must not be compared to a platform's finished days.
 *  - «بيانات غير مكتملة»: a complete day inside the period has no figures for an account bound to the
 *    project. The tooltip NAMES the accounts and the days, and says only what the sync history holds:
 *    «no figures that day» or «that day's sync failed». It never offers a reason it was not given.
 *
 * Accounts that keep another time zone are named in the same tooltip: their «16 October» is not the same
 * hours as ours, so a total can differ from the platform's by the hours between.
 */
export function PeriodStatus({ projectId, period, className = '', testId = 'period-status' }: {
  projectId?: string | null
  period: Period
  className?: string
  testId?: string
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const q = usePeriodCoverage(projectId, period)
  const cov = q.data
  /*
   * PERIOD-ONGOING-BADGE-OFF-001 — Owner 2026-11-21: «جاري — غير مكتمل» read as doubt about the figures, beside a period that
   * simply includes today. It is not drawn any more; a real gap in the data still says so («بيانات غير مكتملة»).
   */
  const incomplete = Boolean(cov?.incomplete)
  const otherZone = (cov?.accounts ?? []).filter((a) => a.timezone_differs)

  if (!incomplete && otherZone.length === 0) return null

  return (
    <span className={`inline-flex flex-wrap items-center gap-1.5 ${className}`} data-testid={testId}>
      {incomplete && cov && (
        <Chip tone="warning" testId={`${testId}-incomplete`} label={ar ? 'بيانات غير مكتملة' : 'Incomplete data'} tip={<CoverageTip cov={cov} ar={ar} />} />
      )}
      {!incomplete && otherZone.length > 0 && cov && (
        <Chip tone="neutral" testId={`${testId}-zone`} label={ar ? 'حسابات بتوقيت مختلف' : 'Accounts in another time zone'} tip={<CoverageTip cov={cov} ar={ar} zonesOnly />} />
      )}
    </span>
  )
}

function Chip({ tone, label, tip, testId }: { tone: 'warning' | 'neutral'; label: string; tip: React.ReactNode; testId: string }) {
  const id = useId()
  const [open, setOpen] = useState(false)
  const palette = tone === 'warning' ? 'bg-[var(--warning-background)] text-warning' : 'bg-surface-secondary text-text-secondary'

  return (
    <span className="relative inline-flex" onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)}>
      <button
        type="button" data-testid={testId} aria-describedby={open ? id : undefined} aria-expanded={open}
        onClick={() => setOpen((v) => !v)} onFocus={() => setOpen(true)} onBlur={() => setOpen(false)}
        onKeyDown={(e) => { if (e.key === 'Escape') setOpen(false) }}
        className={`inline-flex min-h-9 items-center gap-1.5 rounded-[var(--radius-pill)] px-2.5 py-0.5 text-xs font-bold ${palette}`}
      >
        <Info className="h-3.5 w-3.5" aria-hidden />
        {label}
      </button>
      {open && (
        <span
          role="tooltip" id={id} data-testid={`${testId}-tip`}
          className="absolute start-0 top-full z-[70] mt-1.5 w-[min(22rem,calc(100vw-2rem))] rounded-xl border border-border-strong bg-surface-elevated p-3 text-start text-sm font-normal text-text-primary shadow-[var(--shadow-large,0_8px_24px_rgba(0,0,0,.18))]"
        >
          {tip}
        </span>
      )}
    </span>
  )
}

function CoverageTip({ cov, ar, zonesOnly = false }: { cov: PeriodCoverage; ar: boolean; zonesOnly?: boolean }) {
  const gaps = zonesOnly ? [] : cov.accounts.filter((a) => a.missing_count > 0)
  const zones = cov.accounts.filter((a) => a.timezone_differs)

  return (
    <span className="block space-y-2">
      {gaps.map((a) => (
        <span key={a.account_id} className="block" data-testid="period-gap-account">
          <span className="block font-semibold">{a.name} <span className="font-normal text-text-secondary">· {platformLabel(a.provider, ar)}</span></span>
          <span className="block text-text-secondary">
            {ar ? 'أيام بلا أرقام: ' : 'Days with no figures: '}
            {a.missing_days.map((d, i) => (
              <span key={d}>{i > 0 ? '، ' : ''}<Num>{formatDay(d, ar, false)}</Num>{a.failed_days.includes(d) ? (ar ? ' (فشلت المزامنة)' : ' (sync failed)') : ''}</span>
            ))}
            {a.truncated && <span> … ({countedDays(a.missing_count, ar ? 'ar' : 'en')})</span>}
          </span>
        </span>
      ))}
      {zones.length > 0 && (
        <span className="block border-t border-border pt-2 text-text-secondary" data-testid="period-zone-note">
          {ar ? 'حسابات بتوقيت مختلف عن الرياض: ' : 'Accounts on a different time zone from Riyadh: '}
          {zones.map((a, i) => <span key={a.account_id}>{i > 0 ? '، ' : ''}{a.name} (<Num>{a.timezone}</Num>)</span>)}
          {ar ? '. حدود يومها لا تطابق يومنا بالساعة.' : '. Their day boundary is not the same hours as ours.'}
        </span>
      )}
    </span>
  )
}
