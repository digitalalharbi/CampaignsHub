/**
 * PORTAL-REQUEST-STEPS-001 — «حالات الطلب: جديد / قيد التنفيذ / تم، ويراها العميل في بوابته بشريط من
 * 3 مراحل» (Owner, MASTER_FINAL Appendix A task 7).
 *
 * The status key the portal already receives is folded into three steps. Nothing about the request
 * changes and nothing new is read: a status the agency keeps internal already arrives as
 * `in_progress` (ClientPortalController), so the client never learns more than before. A cancelled
 * request is off the path and draws no step at all, rather than a full bar that reads as «تم».
 */
export type RequestStep = 0 | 1 | 2

const DONE = ['completed', 'delivered']
const OFF_PATH = ['cancelled', 'rejected', 'archived']

export function requestStep(status: string): RequestStep | null {
  if (OFF_PATH.includes(status)) return null
  if (status === 'new') return 0
  if (DONE.includes(status)) return 2

  return 1
}

const LABELS: { ar: string; en: string }[] = [
  { ar: 'جديد', en: 'New' },
  { ar: 'قيد التنفيذ', en: 'In progress' },
  { ar: 'تم', en: 'Done' },
]

export function RequestSteps({ status, ar }: { status: string; ar: boolean }) {
  const step = requestStep(status)
  if (step === null) return null

  return (
    <ol data-testid="request-steps" data-step={step} className="grid grid-cols-3 gap-1" aria-label={ar ? 'مراحل الطلب' : 'Request steps'}>
      {LABELS.map((l, i) => (
        <li key={l.en} aria-current={i === step ? 'step' : undefined} className="min-w-0">
          <div className={`h-1.5 rounded-full ${i <= step ? (step === 2 ? 'bg-success' : 'bg-brand-500') : 'bg-surface-secondary'}`} />
          <span className={`mt-1 block truncate text-xs ${i === step ? 'font-semibold text-text-primary' : 'text-text-muted'}`}>{ar ? l.ar : l.en}</span>
        </li>
      ))}
    </ol>
  )
}
