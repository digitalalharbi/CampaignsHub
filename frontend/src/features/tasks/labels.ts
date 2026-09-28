/**
 * What a task's state and urgency are CALLED, in one place.
 *
 * These lived inside `TasksPage` and were reachable only from it, so every other surface that shows
 * a task printed the column: the project integrations page listed «in_progress» and «high» beside an
 * Arabic heading. A second copy would have drifted — the two words for `waiting_client` would not
 * have stayed the same sentence — which is the reason `campaigns/labels.ts` exists for exactly this.
 *
 * An unknown value shows as ITSELF: `medium` is a legacy priority some services still write, and a
 * stray status must be visible rather than silently renamed to something the product does define.
 */
export const STATUS_META: Record<string, { ar: string; en: string; tone: string }> = {
  open: { ar: 'مفتوحة', en: 'Open', tone: 'bg-info/15 text-info' },
  backlog: { ar: 'قائمة الانتظار', en: 'Backlog', tone: 'bg-surface-hover text-text-secondary' },
  todo: { ar: 'للتنفيذ', en: 'To do', tone: 'bg-info/15 text-info' },
  in_progress: { ar: 'قيد التنفيذ', en: 'In progress', tone: 'bg-brand-600/15 text-brand-600' },
  waiting_client: { ar: 'بانتظار العميل', en: 'Waiting on client', tone: 'bg-warning/15 text-warning' },
  blocked: { ar: 'متوقفة', en: 'Blocked', tone: 'bg-danger/15 text-danger' },
  review: { ar: 'مراجعة', en: 'Review', tone: 'bg-info/15 text-info' },
  completed: { ar: 'مكتملة', en: 'Completed', tone: 'bg-success/15 text-success' },
  cancelled: { ar: 'ملغاة', en: 'Cancelled', tone: 'bg-surface-hover text-text-muted' },
}
export const PRIORITY_META: Record<string, { ar: string; en: string; tone: string }> = {
  low: { ar: 'منخفضة', en: 'Low', tone: 'text-text-muted' },
  normal: { ar: 'عادية', en: 'Normal', tone: 'text-text-secondary' },
  medium: { ar: 'متوسطة', en: 'Medium', tone: 'text-info' }, // legacy value written by some services
  high: { ar: 'عالية', en: 'High', tone: 'text-warning' },
  urgent: { ar: 'عاجلة', en: 'Urgent', tone: 'text-danger' },
}

export const statusLabel = (s: string, ar: boolean) => (STATUS_META[s] ? (ar ? STATUS_META[s].ar : STATUS_META[s].en) : s)
export const priorityLabel = (p: string, ar: boolean) => (PRIORITY_META[p] ? (ar ? PRIORITY_META[p].ar : PRIORITY_META[p].en) : p)
