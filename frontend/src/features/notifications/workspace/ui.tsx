import type { InputHTMLAttributes, ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Lock } from 'lucide-react'
import type { EntitlementKey } from '@/lib/notifications'
import { isLocked, showsUpgrade } from '@/lib/notifications'
import { usePortalPath } from '@/app/portalPath'
import { useEntitlements } from './api'
import { useWords, type Terms } from './strings'

/** Compact controls: the settings page is a fitted page, so fields are 36px, not the 56px form default. */
export const fieldCls =
  'h-9 w-full min-w-0 rounded-lg border border-border bg-surface px-3 text-sm text-text-primary outline-none ' +
  'focus:border-brand-500 focus:ring-2 focus:ring-brand-500/15 disabled:cursor-not-allowed disabled:opacity-60'

export function TextBox(props: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={`${fieldCls} ${props.className ?? ''}`} />
}

export function Labelled({ label, hint, children }: { label: string; hint?: string; children: ReactNode }) {
  return (
    <label className="block min-w-0">
      <span className="mb-1 block text-xs font-semibold text-text-secondary">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-text-muted">{hint}</span>}
    </label>
  )
}

/** A pressable chip that is on or off — roles, channels, weekdays. */
export function ToggleChip({
  on, onClick, children, disabled, testid,
}: { on: boolean; onClick: () => void; children: ReactNode; disabled?: boolean; testid?: string }) {
  return (
    <button
      type="button"
      aria-pressed={on}
      disabled={disabled}
      data-testid={testid}
      onClick={onClick}
      className={`h-7 shrink-0 rounded-full border px-2.5 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${
        on ? 'border-brand-600 bg-brand-600 text-white' : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'
      }`}
    >
      {children}
    </button>
  )
}

/** The state of one entitlement key as the server reports it. `undefined` while loading means «do not lock». */
export function useGate(key: EntitlementKey) {
  const { data } = useEntitlements()
  const state = data?.entitlements.find((e) => e.key === key)

  return { state, locked: isLocked(state), editable: state === undefined ? true : state.can_edit, upgrade: showsUpgrade(state) }
}

/** The lock and «ترقية» shown where a feature the plan does not include would be. Driven by the server entitlement state. */
export function LockedNote({ entitlement, terms, compact = false }: { entitlement: EntitlementKey; terms?: Terms; compact?: boolean }) {
  const { t } = useWords(terms)
  const to = usePortalPath()
  const { state, editable } = useGate(entitlement)
  if (state === undefined || (editable && state.mode !== 'grace')) return null
  const grace = state.mode === 'grace'

  return (
    <div
      data-testid={`locked-${entitlement}`}
      role="status"
      className={`flex flex-wrap items-center gap-2 rounded-lg border px-3 ${compact ? 'py-1' : 'py-2'} text-sm ${
        grace ? 'border-warning/40 bg-warning-soft text-text-primary' : 'border-border bg-surface-secondary text-text-secondary'
      }`}
    >
      <Lock size={15} aria-hidden className="shrink-0" />
      <span className="min-w-0 flex-1">{grace ? t('grace') : t('locked')}</span>
      <Link
        to={to('/subscriptions')}
        data-testid={`upgrade-${entitlement}`}
        className="rounded-lg bg-brand-600 px-3 py-1 text-xs font-bold text-white hover:bg-brand-700"
      >
        {t('upgrade')}
      </Link>
    </div>
  )
}

export function StatusPill({ tone, children }: { tone: 'success' | 'warning' | 'danger' | 'neutral' | 'info'; children: ReactNode }) {
  const cls = {
    success: 'bg-success-soft text-success', warning: 'bg-warning-soft text-warning', danger: 'bg-danger-soft text-danger',
    neutral: 'bg-surface-secondary text-text-secondary', info: 'bg-[var(--info-background)] text-info',
  }[tone]

  return <span className={`inline-flex h-6 items-center rounded-full px-2 text-xs font-bold ${cls}`}>{children}</span>
}

/** A scrolling body inside a fitted page: the layer scrolls, never the document. */
export function TabBody({ children, testid }: { children: ReactNode; testid?: string }) {
  return <div data-testid={testid} className="min-h-0 flex-1 overflow-y-auto overflow-x-hidden pe-1">{children}</div>
}
