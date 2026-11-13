import { Check, Lock, Minus } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { SubscriptionPlan } from './api'
import { featureKeys, includes, ladder, limitKeys, planWith } from './planAccess'

/**
 * «قارن الباقات» — every plan side by side, read from the plans the admin console defines (NAV-HOME-PLANS-001).
 *
 * Rows are the union of the features and limits any plan names, so a feature added in the console appears here without a
 * release. A plan without a feature shows the lock and the NAME of the plan that has it.
 */
export function PlanCompare({
  plans, currentCode, featureLabel, limitLabel, valueLabel, unlimited, ar,
}: {
  plans: readonly SubscriptionPlan[]
  currentCode: string | null
  featureLabel: (key: string) => string
  limitLabel: (key: string) => string
  valueLabel: (value: string) => string
  unlimited: string
  ar: boolean
}) {
  const ordered = ladder(plans)
  const name = (p: SubscriptionPlan) => (ar ? p.name_ar || p.name : p.name)

  const cell = (p: SubscriptionPlan, key: string) => {
    if (!includes(p, key)) return <Lock size={14} className="mx-auto text-text-muted" aria-label={ar ? 'غير مشمولة' : 'Not included'} />
    const v = p.features[key]

    return typeof v === 'string' ? <span className="text-xs font-semibold">{valueLabel(v)}</span> : <Check size={15} className="mx-auto text-success" aria-label={ar ? 'مشمولة' : 'Included'} />
  }

  return (
    <section id="plans" data-testid="plan-compare" className="rounded-2xl border border-border bg-surface p-3">
      <h2 className="mb-2 text-sm font-bold text-text-primary">{ar ? 'قارن الباقات' : 'Compare plans'}</h2>
      <table className="w-full table-fixed text-sm">
        <thead>
          <tr className="text-xs text-text-secondary">
            <th className="w-[34%] p-1.5 text-start font-semibold" />
            {ordered.map((p) => (
              <th key={p.code} className={`p-1.5 text-center font-bold ${p.code === currentCode ? 'text-brand-600' : 'text-text-primary'}`}>
                <span className="block truncate">{name(p)}</span>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {limitKeys(plans).map((k) => (
            <tr key={`l-${k}`} className="border-t border-border">
              <td className="truncate p-1.5 text-xs font-medium text-text-primary">{limitLabel(k)}</td>
              {ordered.map((p) => {
                const v = p.limits[k]

                return <td key={p.code} className="p-1.5 text-center text-xs font-semibold"><span className="tnum">{v === null ? unlimited : v === undefined ? <Minus size={13} className="mx-auto text-text-muted" aria-hidden /> : v.toLocaleString('en-US')}</span></td>
              })}
            </tr>
          ))}
          {featureKeys(plans).map((k) => (
            <tr key={`f-${k}`} className="border-t border-border" data-testid={`compare-feature-${k}`}>
              <td className="truncate p-1.5 text-xs font-medium text-text-primary">{featureLabel(k)}</td>
              {ordered.map((p) => <td key={p.code} className="p-1.5 text-center">{cell(p, k)}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}

/**
 * A feature the current plan does not include: a lock and the plan that has it. Pressing it opens the comparison on the
 * subscription page of the portal the reader is in.
 */
export function LockedFeature({
  feature, plans, to, label,
}: { feature: string; plans: readonly SubscriptionPlan[]; to: string; label: string }) {
  const owner = planWith(plans, feature)

  return (
    <Link to={`${to}?compare=1#plans`} data-testid={`locked-${feature}`} className="inline-flex items-center gap-1.5 rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-semibold text-text-secondary hover:text-text-primary">
      <Lock size={12} aria-hidden /> {label}{owner ? ` · ${owner.name_ar || owner.name}` : ''}
    </Link>
  )
}
