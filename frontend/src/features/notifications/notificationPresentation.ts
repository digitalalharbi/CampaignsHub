import type { AppNotification } from './api'

/**
 * UX-NOTIFICATION-CARD-001 — what a notification row is allowed to say about itself.
 *
 * The centre drew every row identically apart from a 2px dot, and the dot was set to
 * `bg-transparent` once the row was read. So a read CRITICAL alert and a read INFO note were the same
 * object on screen: the severity a system took the trouble to record survived exactly until somebody
 * looked at it. An operator scanning yesterday's list could not tell a failed sync from a finished
 * report, which is the whole job of that list.
 *
 * Scope was missing too. A notification is written for a tenant whose readers work in different
 * scopes, and `project_id === null` means «this is about the whole portfolio» — a distinction this
 * product refuses to blur everywhere else, and one the centre simply did not show.
 *
 * Pure functions, in their own file, because the centre is a dropdown inside two shells and a
 * presentation rule that lives inside it cannot be tested without mounting both.
 */
export type NotificationScope = 'project' | 'portfolio'

/**
 * Portfolio when the row names no project — the same rule the API resource states.
 *
 * Derived here rather than added to the payload: the field it is derived FROM is already sent, and a
 * second statement of one fact is a second thing that can disagree.
 */
export function notificationScope(n: Pick<AppNotification, 'project_id'>): NotificationScope {
  return n.project_id === null ? 'portfolio' : 'project'
}

export function scopeLabel(scope: NotificationScope, ar: boolean): string {
  if (scope === 'portfolio') return ar ? 'كل المشاريع' : 'All projects'

  return ar ? 'مشروع' : 'Project'
}

/**
 * Severity, kept after the row is read.
 *
 * The tone no longer depends on `status`. A read row is quieter — that is what `read` should cost it —
 * but it still says WHICH kind of thing it was, because that is the part a reader came back for.
 */
export function severityTone(severity: AppNotification['severity']): 'info' | 'success' | 'warning' | 'danger' {
  if (severity === 'critical') return 'danger'
  if (severity === 'warning') return 'warning'
  if (severity === 'success') return 'success'

  return 'info'
}

export function severityLabel(severity: AppNotification['severity'], ar: boolean): string {
  switch (severity) {
    case 'critical':
      return ar ? 'حرج' : 'Critical'
    case 'warning':
      return ar ? 'يحتاج انتباهًا' : 'Needs attention'
    case 'success':
      return ar ? 'تم' : 'Done'
    default:
      return ar ? 'معلومة' : 'Info'
  }
}

export type TimeBucket = 'today' | 'yesterday' | 'earlier' | 'undated'

/**
 * Today, yesterday, earlier — the grouping a reader actually scans by.
 *
 * Every row previously carried a full `toLocaleString('en-CA')` stamp, so a list of twelve showed
 * twelve near-identical strings and the reader compared them character by character to find what is
 * new. A bucket answers that at a glance and the exact stamp stays available on hover.
 *
 * `undated` is a real state: a row whose `created_at` never arrived is not «today», and guessing would
 * put an unknown age at the top of the list.
 */
export function timeBucket(createdAt: string | null | undefined, now: Date = new Date()): TimeBucket {
  if (!createdAt) return 'undated'

  const at = new Date(createdAt)
  if (Number.isNaN(at.getTime())) return 'undated'

  const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
  const at0 = new Date(at.getFullYear(), at.getMonth(), at.getDate()).getTime()

  if (at0 >= startOfToday) return 'today'
  if (at0 >= startOfToday - 86_400_000) return 'yesterday'

  return 'earlier'
}

export function bucketLabel(bucket: TimeBucket, ar: boolean): string {
  switch (bucket) {
    case 'today':
      return ar ? 'اليوم' : 'Today'
    case 'yesterday':
      return ar ? 'أمس' : 'Yesterday'
    case 'earlier':
      return ar ? 'أقدم' : 'Earlier'
    default:
      return ar ? 'بدون تاريخ' : 'Undated'
  }
}

/**
 * The buckets in reading order, each with its rows — and empty ones omitted.
 *
 * Order is fixed rather than derived from the data: a list whose headings appear in a different order
 * on every refresh is a list nobody can learn.
 */
export function groupByTime<T extends Pick<AppNotification, 'created_at'>>(
  items: readonly T[],
  now: Date = new Date(),
): Array<{ bucket: TimeBucket; items: T[] }> {
  const order: TimeBucket[] = ['today', 'yesterday', 'earlier', 'undated']
  const held = new Map<TimeBucket, T[]>()

  for (const item of items) {
    const bucket = timeBucket(item.created_at, now)
    held.set(bucket, [...(held.get(bucket) ?? []), item])
  }

  return order
    .filter((bucket) => (held.get(bucket)?.length ?? 0) > 0)
    .map((bucket) => ({ bucket, items: held.get(bucket)! }))
}
