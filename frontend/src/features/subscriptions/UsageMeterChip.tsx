import { useQuery } from '@tanstack/react-query'
import { Link, useLocation } from 'react-router-dom'
import { Gauge } from 'lucide-react'
import { portalBaseOf } from '@/app/portalPath'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { getCurrent } from './api'
import { METER_LABELS, pickMeter, type Threshold } from './planAccess'

/**
 * The subscriber's usage, always in view — «المشاريع 8/10» (Owner 2026-11-13, PLAN-LIMITS-METER-001).
 *
 * One compact chip at the top of the app, speaking about the limit the workspace is closest to. Colour follows the
 * Owner's three points: normal, amber from 75% and red at the limit. At the limit it tells the truth the server enforces:
 * adding more of that item is refused, and the way through is the upgrade — never anything already running.
 *
 * Shares the subscription page's cached read (`['subscriptions','current']`), so showing it costs no second request.
 * Not drawn without `subscriptions.view` (it would only ever 403), and not drawn when nothing is capped.
 */
const TONE: Record<Threshold, string> = {
  unlimited: 'border-border text-text-secondary',
  ok: 'border-border text-text-secondary',
  near: 'border-warning/50 bg-[var(--warning-background)] text-warning',
  high: 'border-warning/60 bg-[var(--warning-background)] text-warning',
  full: 'border-danger/60 bg-[var(--negative-background)] text-danger',
}

export function UsageMeterChip() {
  const ar = useUi((s) => s.locale) === 'ar'
  const canView = useAuth((s) => s.hasPermission('subscriptions.view'))
  const { pathname } = useLocation()
  const current = useQuery({ queryKey: ['subscriptions', 'current'], queryFn: getCurrent, retry: false, enabled: canView, staleTime: 60_000 })

  const meter = current.data ? pickMeter(current.data.usage) : null
  if (!canView || meter === null) return null

  const label = METER_LABELS[meter.metric]?.[ar ? 'ar' : 'en'] ?? meter.metric
  const full = meter.state === 'full'
  const hint = full
    ? (ar ? 'وصلت الحد. الجديد ما ينضاف. رقِّ باقتك.' : 'Limit reached. New ones are blocked. Upgrade your plan.')
    : (ar ? 'استخدام باقتك' : 'Your plan usage')

  return (
    <Link
      to={`${portalBaseOf(pathname)}/subscriptions${full ? '?compare=1#plans' : ''}`}
      data-testid="usage-chip"
      data-state={meter.state}
      title={`${hint} — ${label} ${meter.used}/${meter.limit}`}
      aria-label={`${label} ${meter.used} / ${meter.limit}. ${hint}`}
      className={`flex h-9 min-w-0 max-w-[10.5rem] shrink items-center gap-1.5 rounded-xl border px-2 text-xs sm:px-2.5 font-bold transition-colors hover:border-border-strong sm:max-w-none ${TONE[meter.state]}`}
    >
      <Gauge size={14} className="hidden shrink-0 sm:block" aria-hidden />
      <span className="truncate">{label}</span>
      <span className="tnum shrink-0">{meter.used}/{meter.limit}</span>
    </Link>
  )
}
