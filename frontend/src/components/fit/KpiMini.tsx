import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { FIT_CARD, FIT_CARD_PAD, FIT_GAP, FIT_KPI_VALUE } from '@/styles/scale'
import { InfoTip } from './InfoTip'

export type KpiTone = 'brand' | 'success' | 'warning' | 'danger' | 'info' | 'neutral'

const CHIP: Record<KpiTone, string> = {
  brand: 'bg-brand-primary-soft text-brand-600',
  success: 'bg-success-soft text-success',
  warning: 'bg-warning-soft text-warning',
  danger: 'bg-danger-soft text-danger',
  info: 'bg-[var(--info-background)] text-info',
  neutral: 'bg-surface-secondary text-text-secondary',
}

/**
 * NOV-FIT-001 — the compact KPI card: a big number, a short name, a tinted icon.
 *
 * About 64px tall where `StatCard` is about 110, so a row of four costs a fitted page one block rather than two.
 * What `StatCard` explained in a hint line is the `hint` tooltip here. The number is always `dir="ltr"` and
 * `tnum`: it reads left-to-right in every language and keeps its figures one width.
 */
export function KpiMini({
  label, value, tone = 'neutral', icon: Icon, hint, testid,
}: { label: string; value: ReactNode; tone?: KpiTone; icon?: LucideIcon; hint?: string; testid?: string }) {
  return (
    <div data-testid={testid} className={`flex min-w-0 items-center gap-3 ${FIT_CARD} ${FIT_CARD_PAD}`}>
      {Icon && (
        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${CHIP[tone]}`}>
          <Icon size={19} aria-hidden />
        </span>
      )}
      <div className="min-w-0">
        <div dir="ltr" className={`${FIT_KPI_VALUE} text-start text-text-primary`}>{value}</div>
        <div className="flex min-w-0 items-center gap-0.5 text-xs font-semibold text-text-secondary">
          <span className="truncate">{label}</span>
          {hint && <InfoTip text={hint} label={hint} />}
        </div>
      </div>
    </div>
  )
}

/** Two across on a phone, four from `md` — the one grid every fitted page's figures sit in. */
export function KpiStrip({ children, testid, cols = 4 }: { children: ReactNode; testid?: string; cols?: 2 | 3 | 4 | 5 }) {
  const wide = { 2: 'md:grid-cols-2', 3: 'md:grid-cols-3', 4: 'md:grid-cols-4', 5: 'md:grid-cols-5' }[cols]

  return <div data-testid={testid} className={`grid grid-cols-2 ${FIT_GAP} ${wide}`}>{children}</div>
}
