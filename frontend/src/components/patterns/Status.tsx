import type { ReactNode } from 'react'
import { Num } from '@/components/ui/Num'
import { useUi } from '@/stores/ui'

/**
 * PRODUCT-VISUAL-001 §6 — the two small pieces the shared header did not already have.
 *
 * Deliberately not a header component. `PageIntro` is this product's header and has been since
 * UX-PAGE-HERO-001: eyebrow, title, badges, purpose, meta, actions and KPIs. A second one would be
 * the parallel engine §0 forbids, and the first draft of this file was exactly that — written
 * before its author looked, and deleted after.
 *
 * What survives here is what `PageIntro` genuinely lacks: the period a figure is FOR, and the
 * heading a section inside a card needs. Freshness belongs to `DataFreshness`, health chips to
 * `Badge`, and figures to `StatCard`; each of those is extended where it falls short rather than
 * replaced.
 */

/**
 * The period these figures are for.
 *
 * A number with no period is not checkable: «184,648 SAR» is either a good month or a bad week and
 * the reader cannot tell which. It belongs in `PageIntro`'s `meta`, beside the freshness, because
 * those two together are the whole of «can I trust this figure».
 */
export function PeriodLabel({ from, to, testId = 'period' }: { from: string; to: string; testId?: string }) {
  const ar = useUi((s) => s.locale) === 'ar'

  return (
    <span className="inline-flex items-center gap-1.5 tnum" data-testid={testId}>
      {ar ? 'الفترة' : 'Period'} <Num>{from}</Num> → <Num>{to}</Num>
    </span>
  )
}

/**
 * A section's heading AND the question it answers.
 *
 * Charts are not decoration: each exists because somebody asks something, and a chart whose
 * question is not stated is one the reader has to reverse-engineer from its axes. The question
 * costs a line and saves that.
 *
 * Distinct from `Panel`, which DRAWS a card. This is the heading inside a card that already exists,
 * so a surface composing several sections in one card does not end up with cards inside cards.
 */
export function SectionHeader({ title, question, action, testId }: {
  title: string
  question?: string
  action?: ReactNode
  testId?: string
}) {
  return (
    <div className="mb-3 flex flex-wrap items-end justify-between gap-2" data-testid={testId}>
      <div className="min-w-0">
        <h2 className="text-sm font-bold text-text-primary">{title}</h2>
        {question !== undefined && <p className="text-xs text-text-secondary">{question}</p>}
      </div>
      {action !== undefined && <div className="shrink-0">{action}</div>}
    </div>
  )
}
