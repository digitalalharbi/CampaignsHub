import { useState } from 'react'
import { ChevronDown, EyeOff, ShieldCheck } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Num } from '@/components/ui/Num'
import { platformColor } from '@/features/analytics/components'
import { moneyExact } from '@/features/analytics/format'
import { providerLabel } from '@/features/campaigns/labels'
import { FindingKpiTile, formatKpi } from '@/features/recommendations/FindingKpiTile'
import { natureLabel, severityLabel } from '@/features/recommendations/findingCopy'
import type { ActionSeverity } from '@/features/recommendations/actionCenter'
import {
  attentionAction, attentionHeadline, attentionImpact, attentionImpactBasis, toFindingKpi, type AttentionItem,
} from './attention'

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — the end of the report's story: what needs attention → one action.
 *
 * Each item is a compact block, not a paragraph: severity and whether it is a problem or an
 * opportunity, the platform and objective it is about, the KPI before and now drawn as bars, at most
 * one impact figure, ONE action, and a drill-down that goes platform → content and no deeper.
 *
 * It draws what the server sent. An empty list renders nothing at all — no heading, no «all clear»
 * card — because a section with no qualifying findings is not in the report.
 *
 * `onDecide` is the operator's control and exists only on the operator's own screen; a client page
 * never passes it, and the client payload never carries the fields it reads.
 */
export function AttentionBlocks({
  items,
  ar,
  onOpenContent,
  onDecide,
  busyKey,
}: {
  items: AttentionItem[] | null | undefined
  ar: boolean
  onOpenContent?: (contentKey: string) => void
  onDecide?: (item: AttentionItem, decision: 'approved' | 'hidden' | null) => void
  busyKey?: string | null
}) {
  if (!items || items.length === 0) return null

  return (
    <section className="flex flex-col gap-3" data-testid="report-attention" aria-label={ar ? 'ما يحتاج انتباهًا' : 'What needs attention'}>
      <h3 className="text-base font-bold tracking-tight text-text-primary">{ar ? 'ما يحتاج انتباهًا' : 'What needs attention'}</h3>
      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        {items.map((item) => (
          <AttentionBlock key={item.key} item={item} ar={ar} onOpenContent={onOpenContent} onDecide={onDecide} busy={busyKey === item.key} />
        ))}
      </div>
    </section>
  )
}

function AttentionBlock({
  item,
  ar,
  onOpenContent,
  onDecide,
  busy,
}: {
  item: AttentionItem
  ar: boolean
  onOpenContent?: (contentKey: string) => void
  onDecide?: (item: AttentionItem, decision: 'approved' | 'hidden' | null) => void
  busy: boolean
}) {
  const [open, setOpen] = useState(false)
  const locale = ar ? 'ar' : 'en'
  const primary = item.kpis.find((k) => k.primary) ?? item.kpis[0]
  const peer = primary?.peer

  return (
    <article
      data-testid={`attention-${item.key}`}
      data-severity={item.severity}
      data-nature={item.nature}
      className={`flex min-w-0 flex-col gap-3 rounded-2xl border border-border border-s-4 bg-surface p-4 shadow-[var(--shadow-small)] break-inside-avoid ${RAIL[item.severity]}`}
    >
      <header className="flex flex-wrap items-center gap-2">
        <Badge tone={SEVERITY_TONE[item.severity]} data-testid="attention-severity">{severityLabel(item.severity, ar)}</Badge>
        <Badge tone={item.nature === 'problem' ? 'danger' : 'success'} data-testid="attention-nature">{natureLabel(item.nature, ar)}</Badge>
        {item.audience === 'operator' && (
          <Badge tone="neutral" data-testid="attention-operator-only">{ar ? 'داخلي — لا يظهر للعميل إلا باعتماد' : 'Internal — shown to a client only if approved'}</Badge>
        )}
      </header>

      <div className="min-w-0">
        <h4 className="text-sm font-bold text-text-primary">{attentionHeadline(item.code, ar)}</h4>
        <p className="mt-1 flex min-w-0 flex-wrap items-center gap-2 text-xs text-text-secondary" data-testid="attention-subject">
          <span className="inline-flex items-center gap-1.5">
            <span className="h-2.5 w-2.5 rounded-full" style={{ background: platformColor(item.platform) }} aria-hidden />
            {providerLabel(item.platform, locale)}
          </span>
          <span aria-hidden>·</span>
          <span>{ar ? item.family_label_ar : item.family_label_en}</span>
        </p>
      </div>

      {item.kpis.length > 0 && (
        <dl className="grid min-w-0 grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-2" data-testid="attention-kpis">
          {item.kpis.map((k) => <FindingKpiTile key={k.key} kpi={toFindingKpi(k)} ar={ar} />)}
        </dl>
      )}

      {primary && peer && (
        <p className="text-xs text-text-secondary" data-testid="attention-peer">
          {providerLabel(peer.platform, locale)}: <span className="font-bold text-text-primary"><Num>{formatKpi(toFindingKpi(primary), peer.value)}</Num></span>
        </p>
      )}

      {item.impact && (
        <div className="rounded-xl bg-surface-secondary px-3 py-2" data-testid="attention-impact">
          <p className="flex flex-wrap items-baseline gap-2 text-sm">
            <span className="text-text-secondary">{attentionImpact(item.impact.kind, ar)}</span>
            <span className="text-base font-extrabold text-text-primary"><Num>{moneyExact(item.impact.amount, item.impact.currency)}</Num></span>
          </p>
          <p className="text-[11px] text-text-muted">{attentionImpactBasis(item.impact.kind, ar)}</p>
        </div>
      )}

      <footer className="flex flex-col gap-2 border-t border-border pt-3">
        <p className="text-sm font-semibold text-text-primary" data-testid="attention-action">{attentionAction(item.action, ar)}</p>

        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          aria-expanded={open}
          data-testid="attention-evidence-toggle"
          className="inline-flex w-fit items-center gap-1 text-xs font-bold text-brand-600 print:hidden"
        >
          {ar ? 'الدليل: المنصة ← المحتوى' : 'Evidence: platform → content'}
          <ChevronDown size={13} className={open ? 'rotate-180' : ''} aria-hidden />
        </button>
        {/* Printed open: a PDF has no click, so the drill-down is laid out rather than hidden. */}
        <div className={`${open ? '' : 'hidden'} print:block`} data-testid="attention-evidence">
          <p className="text-xs text-text-secondary">{providerLabel(item.evidence.platform, locale)}</p>
          {item.evidence.content.length > 0 ? (
            <ul className="mt-1 flex flex-col gap-1">
              {item.evidence.content.map((c, i) => (
                <li key={c.content_key ?? i} className="min-w-0 truncate text-xs">
                  {c.content_key && onOpenContent ? (
                    <button type="button" className="font-semibold text-brand-600 hover:underline" onClick={() => onOpenContent(c.content_key!)}>
                      {c.name ?? (ar ? 'محتوى بلا اسم' : 'Untitled content')}
                    </button>
                  ) : (
                    <span className="font-semibold text-text-primary">{c.name ?? (ar ? 'محتوى بلا اسم' : 'Untitled content')}</span>
                  )}
                </li>
              ))}
            </ul>
          ) : (
            <p className="mt-1 text-xs text-text-muted">{ar ? 'لا محتوى معروض من هذه المنصة في هذا التقرير.' : 'No content from this platform is shown in this report.'}</p>
          )}
        </div>

        {onDecide && (
          <div className="flex flex-wrap items-center gap-2 print:hidden" data-testid="attention-decision">
            <button
              type="button"
              disabled={busy}
              onClick={() => onDecide(item, item.decision === 'approved' ? null : 'approved')}
              className={`inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-xs font-semibold disabled:opacity-50 ${item.decision === 'approved' ? 'border-success text-success' : 'border-border text-text-secondary hover:bg-surface-hover'}`}
            >
              <ShieldCheck size={13} aria-hidden />
              {item.decision === 'approved' ? (ar ? 'معتمد للعميل' : 'Approved for client') : (ar ? 'اعتماد للعميل' : 'Approve for client')}
            </button>
            <button
              type="button"
              disabled={busy}
              onClick={() => onDecide(item, item.decision === 'hidden' ? null : 'hidden')}
              className={`inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-xs font-semibold disabled:opacity-50 ${item.decision === 'hidden' ? 'border-danger text-danger' : 'border-border text-text-secondary hover:bg-surface-hover'}`}
            >
              <EyeOff size={13} aria-hidden />
              {item.decision === 'hidden' ? (ar ? 'مخفي عن العميل' : 'Hidden from client') : (ar ? 'إخفاء عن العميل' : 'Hide from client')}
            </button>
          </div>
        )}
      </footer>
    </article>
  )
}

const RAIL: Record<ActionSeverity, string> = {
  critical: 'border-s-danger',
  warning: 'border-s-warning',
  info: 'border-s-info',
}

const SEVERITY_TONE: Record<ActionSeverity, 'danger' | 'warning' | 'info'> = {
  critical: 'danger',
  warning: 'warning',
  info: 'info',
}
