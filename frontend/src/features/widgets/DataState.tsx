import { Badge } from '@/components/ui/Badge'
import { useUi } from '@/stores/ui'

/**
 * WIDGET-REGISTRY-001 — what KIND of figure a widget is showing, said on the widget itself.
 *
 * The Owner's rule is that no widget lets a reader mistake one kind of number for another, so every widget states its
 * kind with one of these and the five are never merged:
 *
 *  - `measured`     a count of something the product recorded or verified;
 *  - `estimate`     a number that is an estimate by construction (devices per day, never people);
 *  - `unattributed` orders or outcomes with no verified evidence of where they came from;
 *  - `missing`      nothing recorded for this window, which is not the same as zero;
 *  - `demo`         seeded data, never a real result.
 */
export type DataKind = 'measured' | 'estimate' | 'unattributed' | 'missing' | 'demo'

const TONE: Record<DataKind, 'success' | 'info' | 'warning' | 'neutral' | 'danger'> = {
  measured: 'success',
  estimate: 'info',
  unattributed: 'warning',
  missing: 'neutral',
  demo: 'danger',
}

const LABEL: Record<DataKind, { en: string; ar: string }> = {
  measured: { en: 'Measured', ar: 'مقيس' },
  estimate: { en: 'Estimate', ar: 'تقديري' },
  unattributed: { en: 'Unattributed', ar: 'غير منسوب' },
  missing: { en: 'No data', ar: 'لا توجد بيانات' },
  demo: { en: 'Demo', ar: 'تجريبي' },
}

export function DataState({ kind, testId }: { kind: DataKind; testId?: string }) {
  const ar = useUi((u) => u.locale) === 'ar'

  return <span data-kind={kind}><Badge tone={TONE[kind]} data-testid={testId ?? `data-state-${kind}`}>{ar ? LABEL[kind].ar : LABEL[kind].en}</Badge></span>
}
