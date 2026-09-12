import { Link } from 'react-router-dom'
import { AlertTriangle, Gauge, Sparkles } from 'lucide-react'
import { alertCategoryLabel, alertNextAction } from '@/features/alerts/alertTaxonomy'
import { usePortalPath } from '@/app/portalPath'
import { Num } from '@/components/ui/Num'
import type { ActionItem, ActionSeverity } from './actionCenter'
import type { Locale } from '@/stores/ui'

/**
 * RECOMMENDATIONS-ACTION-CENTER-002 — the things the PRODUCT noticed, beside the things people wrote.
 *
 * The page listed recommendations somebody had written. An account where nobody had written one
 * showed nothing to do — while the product knew, at that moment, that a budget was breached, a
 * creative had fatigued and a token was about to expire. Three engines, three surfaces, and the page
 * named «what should I do» showed none of them.
 *
 * ## Each item says where it came from
 *
 * «Fatigue says this creative is spent» and «Sara wrote: stop running this» are different claims
 * with different standing, and a reader is entitled to know which they are looking at. A derived
 * signal rendered as advice would be the product putting words in an operator's mouth.
 *
 * ## And each keeps its own evidence
 *
 * Nothing is re-stated here. A budget item shows the governor's own figures with the governor's own
 * currency; an alert links to the alerts page where `ContextChips` renders the measured values with
 * their units. A generic reader in this file would print «1000» where that one prints «1,000 SAR».
 */
export function OperationalSignals({
  items,
  locale,
}: {
  items: ActionItem[]
  locale: Locale
}) {
  const ar = locale === 'ar'
  const portalTo = usePortalPath()

  if (items.length === 0) {
    return null
  }

  return (
    <section data-testid="operational-signals" className="flex flex-col gap-2">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-sm font-bold text-text-primary">
          {ar ? 'ما رصده النظام' : 'What the product noticed'}
        </h2>
        {/*
          Said once, plainly. These are not somebody's recommendations, and a reader who takes them
          for advice will act on them with more confidence than the evidence carries.
        */}
        <span className="text-[11px] text-text-secondary">
          {ar
            ? 'إشارات مُستنتجة من بيانات الحساب — ليست توصيات كتبها أحد.'
            : 'Signals derived from this account’s own data — not recommendations anybody wrote.'}
        </span>
      </div>

      <ul className="flex flex-col gap-2">
        {items.map((item) => (
          <li
            key={item.id}
            data-testid={`signal-${item.kind}`}
            data-severity={item.severity}
            className="flex items-start gap-3 rounded-2xl border border-border bg-surface p-3"
          >
            <span className={`mt-1 h-2.5 w-2.5 shrink-0 rounded-full ${TONE[item.severity]}`} aria-hidden />

            <div className="flex min-w-0 flex-1 flex-col gap-1">
              {item.kind === 'alert' && (
                <>
                  <span className="flex flex-wrap items-center gap-2">
                    <AlertTriangle size={13} className="shrink-0 text-warning" aria-hidden />
                    <span className="font-semibold text-text-primary">
                      {typeof item.alert.context?.title === 'string' ? item.alert.context.title : item.alert.type}
                    </span>
                    <span className="rounded-full bg-brand-500/10 px-2 py-0.5 text-[10px] font-semibold text-brand-600">
                      {alertCategoryLabel(item.category === 'creative' ? 'other' : item.category, locale)}
                    </span>
                  </span>
                  {alertNextAction(item.alert.type, locale) !== null && (
                    <span className="text-[12px] text-text-secondary">{alertNextAction(item.alert.type, locale)}</span>
                  )}
                  {/* The measured values live on the alerts page, where the chips know their units. */}
                  <Link to={portalTo('/alerts')} className="text-[11px] font-semibold text-brand-600 underline-offset-2 hover:underline">
                    {ar ? 'افتح التنبيه' : 'Open the alert'}
                  </Link>
                </>
              )}

              {item.kind === 'budget' && (
                <>
                  <span className="flex flex-wrap items-center gap-2">
                    <Gauge size={13} className="shrink-0 text-warning" aria-hidden />
                    <span className="font-semibold text-text-primary">
                      {item.limit.state === 'over'
                        ? (ar ? 'تجاوز حدّ إنفاق داخلي' : 'Past an internal spend limit')
                        : item.limit.state === 'approaching'
                          ? (ar ? 'يقترب من حدّ إنفاق داخلي' : 'Approaching an internal spend limit')
                          : (ar ? 'حدّ إنفاق لا يمكن قياسه' : 'A spend limit that cannot be measured')}
                    </span>
                  </span>
                  {/*
                    The governor's own figures, in the governor's own currency. `utilisation` is null
                    for an unmeasurable limit by definition, and printing «0%» beside «cannot be
                    measured» would answer the question it had just refused.
                  */}
                  <span className="text-[12px] text-text-secondary">
                    {item.limit.utilisation !== null && (
                      <><Num>{Math.round(item.limit.utilisation * 100)}%</Num>{' · '}</>
                    )}
                    <Num>{`${Math.round(item.limit.amount).toLocaleString('en-US')} ${item.limit.currency}`}</Num>
                  </span>
                  <Link to={portalTo('/spend-limits')} className="text-[11px] font-semibold text-brand-600 underline-offset-2 hover:underline">
                    {ar ? 'افتح حدود الإنفاق' : 'Open spend limits'}
                  </Link>
                </>
              )}

              {item.kind === 'creative' && (
                <>
                  <span className="flex flex-wrap items-center gap-2">
                    <Sparkles size={13} className="shrink-0 text-warning" aria-hidden />
                    <span className="truncate font-semibold text-text-primary">{item.creative.name}</span>
                    <span className="rounded-full bg-brand-500/10 px-2 py-0.5 text-[10px] font-semibold text-brand-600">
                      {ar ? 'المحتوى' : 'Creative'}
                    </span>
                  </span>
                  {/*
                    The assessor's OWN sentence, never a summary of it. `CreativeFatigue` states what
                    it found and why, and rewording it here would be a second verdict.
                  */}
                  <span className="text-[12px] text-text-secondary">
                    {(ar ? item.creative.fatigue?.reason_ar : item.creative.fatigue?.reason_en)
                      ?? (ar ? 'تراجع أداء هذا المحتوى.' : 'This creative’s performance has fallen away.')}
                  </span>
                  <Link
                    to={portalTo(`/content/${item.creative.id}`)}
                    className="text-[11px] font-semibold text-brand-600 underline-offset-2 hover:underline"
                  >
                    {ar ? 'افتح تحليلات المحتوى' : 'Open the content’s analytics'}
                  </Link>
                </>
              )}
            </div>
          </li>
        ))}
      </ul>
    </section>
  )
}

const TONE: Record<ActionSeverity, string> = {
  critical: 'bg-danger',
  warning: 'bg-warning',
  info: 'bg-info',
}
