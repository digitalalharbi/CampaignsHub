import { useState } from 'react'
import { ChevronDown, Info } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useProject } from '@/stores/project'
import { pickText, useDisclaimer, type ResolvedDisclaimer } from './api'

type Variant = 'compact' | 'full' | 'footer' | 'tooltip' | 'methodology'

const LABELS = {
  ar: { more: 'معرفة المزيد', less: 'إخفاء', title: 'منهجية التقرير وملاحظات البيانات', methodology: 'منهجية الحملات القائمة على الأداء', objective: 'ملاحظة حسب هدف الحملة', freshness: 'تحديث البيانات والإسناد' },
  en: { more: 'Learn more', less: 'Hide', title: 'Report Methodology & Data Notes', methodology: 'Performance-based methodology', objective: 'Objective-specific note', freshness: 'Data freshness & attribution' },
}

/**
 * Unified performance notice / disclaimer. One source of copy, two lengths (short/full), rendered per
 * placement via `variant`. Quiet by default (never a red warning), RTL/LTR + light/dark aware, works
 * at 320px without horizontal scroll. Content comes from a resolved disclaimer (report snapshot) or a
 * projectId (live surfaces) — never hard-coded here.
 */
export function PerformanceNotice({
  data,
  variant = 'compact',
  objective,
  className = '',
}: {
  data?: ResolvedDisclaimer | null
  variant?: Variant
  objective?: string | null
  className?: string
}) {
  const locale = useUi((s) => s.locale)
  const [open, setOpen] = useState(false)
  const t = LABELS[locale]

  if (!data) return null
  const on = (k: string) => (data.enabled?.[k] ?? true) === true
  const short = on('short') ? pickText(data.sections.short, locale) : null
  const full = on('full') ? pickText(data.sections.full, locale) : null
  const freshness = on('freshness') ? pickText(data.sections.freshness, locale) : null
  const methodology = on('methodology') ? pickText(data.sections.methodology, locale) : null
  const objectiveText = on('objectives') && objective ? pickText(data.sections.objectives?.[objective], locale) : null

  if (variant === 'footer') {
    if (!short) return null
    return <p className={`flex items-start gap-1.5 text-[11px] leading-relaxed text-text-muted ${className}`}><Info size={12} className="mt-0.5 shrink-0" /><span>{short}</span></p>
  }

  if (variant === 'tooltip') {
    const tip = [freshness, short].filter(Boolean).join('\n\n')
    if (!tip) return null
    return <span title={tip} className={`inline-flex cursor-help text-text-muted ${className}`} aria-label={tip}><Info size={14} /></span>
  }

  if (variant === 'methodology') {
    return (
      <div className={`space-y-4 ${className}`}>
        <h3 className="text-lg font-extrabold text-text-primary">{t.title}</h3>
        {full && <p className="rounded-xl border border-border bg-surface-secondary p-4 text-sm leading-relaxed text-text-secondary">{full}</p>}
        {methodology && <div><h4 className="mb-1 text-sm font-bold text-text-primary">{t.methodology}</h4><p className="text-sm leading-relaxed text-text-secondary">{methodology}</p></div>}
        {objectiveText && <div><h4 className="mb-1 text-sm font-bold text-text-primary">{t.objective}</h4><p className="text-sm leading-relaxed text-text-secondary">{objectiveText}</p></div>}
        {freshness && <div><h4 className="mb-1 text-sm font-bold text-text-primary">{t.freshness}</h4><p className="text-sm leading-relaxed text-text-secondary">{freshness}</p></div>}
      </div>
    )
  }

  if (variant === 'full') {
    if (!full && !short) return null
    return (
      <div className={`flex items-start gap-2.5 rounded-xl border border-border bg-surface-secondary p-4 ${className}`}>
        <Info size={16} className="mt-0.5 shrink-0 text-text-muted" />
        <p className="text-sm leading-relaxed text-text-secondary">{full ?? short}</p>
      </div>
    )
  }

  /*
   * compact (default): ONE LINE until asked, then the whole notice.
   *
   * VISUAL-FIRST-001 — «normal visible analytical copy should usually be one short sentence, not a
   * paragraph … move deeper explanation to tooltip, expandable details, modal or evidence section.»
   *
   * The short section is written by the operator and on this install runs to twenty-one words across
   * five clauses. Measured across the analytics tabs, it was the longest single text run on SEVEN of
   * them — the same paragraph re-read at the top of every tab, above the work the reader came for.
   *
   * It is CLAMPED rather than removed. A performance disclaimer is not decoration: it stays on the
   * page, in the same place, legible, and the first line is the operator's own opening clause. What
   * changed is that it now occupies one line instead of three, and the rest is one click away — so
   * the notice is disclosed progressively rather than hidden, which is the distinction the
   * requirement draws.
   */
  if (!short) return null

  const hasMore = Boolean(full || freshness)

  return (
    <div className={`rounded-xl border border-border bg-surface-secondary px-3.5 py-2.5 ${className}`}>
      <div className="flex items-start gap-2">
        <Info size={15} className="mt-0.5 shrink-0 text-text-muted" />
        <div className="min-w-0 flex-1">
          <p
            data-testid="performance-notice-short"
            data-clamped={open ? 'false' : 'true'}
            className={`text-[13px] leading-relaxed text-text-secondary ${open ? '' : 'line-clamp-1'}`}
          >
            {short}
          </p>
          {open && hasMore && (
            <div className="mt-2 space-y-2 border-t border-border pt-2">
              {full && <p className="text-[13px] leading-relaxed text-text-secondary">{full}</p>}
              {freshness && <p className="text-xs leading-relaxed text-text-muted">{freshness}</p>}
            </div>
          )}
          {/*
            Offered whenever there is ANYTHING more to read — including when the only thing more is
            the rest of this sentence. Without that, a clamped line with no control would be text
            silently truncated, which is worse than the paragraph it replaced.
          */}
          <button
            onClick={() => setOpen((v) => !v)}
            data-testid="performance-notice-toggle"
            className="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:underline"
          >
            {open ? t.less : t.more}
            <ChevronDown size={13} className={`transition-transform ${open ? 'rotate-180' : ''}`} />
          </button>
        </div>
      </div>
    </div>
  )
}

/** Live variant for dashboard/analytics: fetches the active project's resolved disclaimer. */
export function LivePerformanceNotice({ variant = 'compact', className = '', objective }: { variant?: Variant; className?: string; objective?: string | null }) {
  const projectId = useProject((s) => s.currentProjectId)
  const { data } = useDisclaimer(projectId)
  return <PerformanceNotice data={data} variant={variant} className={className} objective={objective} />
}
