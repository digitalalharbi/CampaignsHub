import { useQuery } from '@tanstack/react-query'
import { Link, useLocation } from 'react-router-dom'
import { ArrowUpRight } from 'lucide-react'
import { portalBaseOf } from '@/app/portalPath'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { getCurrent } from './api'
import { LimitRings } from './LimitRings'
import { METER_LABELS, worstThreshold } from './planAccess'

/**
 * The same meter, in Settings — the plan, a ring per limit, and the way to lift one (PLAN-LIMITS-METER-001).
 *
 * Compact on purpose (Owner 2026-11-13): a header line and the rings in one card. «ترقية» opens the plan comparison on
 * the subscription page, where the existing in-app checkout takes the payment; nothing here charges anything.
 */
export function UsageMeterPanel() {
  const ar = useUi((s) => s.locale) === 'ar'
  const canView = useAuth((s) => s.hasPermission('subscriptions.view'))
  const { pathname } = useLocation()
  const current = useQuery({ queryKey: ['subscriptions', 'current'], queryFn: getCurrent, retry: false, enabled: canView, staleTime: 60_000 })

  if (!canView || !current.data || Object.keys(current.data.usage).length === 0) return null

  const plan = current.data.plan
  const state = worstThreshold(current.data.usage)
  const to = `${portalBaseOf(pathname)}/subscriptions?compare=1#plans`

  return (
    <section data-testid="usage-panel" data-state={state} className="mb-4 grid gap-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-sm font-bold text-text-primary">
          {ar ? 'استخدام باقتك' : 'Your plan usage'}
          {plan && <span className="ms-2 text-xs font-semibold text-text-secondary">{ar ? plan.name_ar || plan.name : plan.name}</span>}
        </h2>
        <Link to={to} data-testid="usage-upgrade" className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-brand-700">
          {ar ? 'ترقية' : 'Upgrade'} <ArrowUpRight size={13} aria-hidden />
        </Link>
      </div>
      <LimitRings
        usage={current.data.usage}
        labelOf={(m) => METER_LABELS[m]?.[ar ? 'ar' : 'en'] ?? m}
        unlimited={ar ? 'غير محدود' : 'Unlimited'}
        ar={ar}
      />
    </section>
  )
}
