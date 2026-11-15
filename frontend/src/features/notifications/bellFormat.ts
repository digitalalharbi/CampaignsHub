import type { BellFilter } from './api'

/**
 * NOTIF-BELL-001 — what the bell's number says.
 *
 * The number is the real unread count. Above 99 it reads «99+» — the badge is a small circle, and a four-digit number in it is
 * unreadable — but the panel header and the server keep the real figure.
 */
export function badgeText(unread: number): string {
  return unread > 99 ? '99+' : String(unread)
}

/** The panel's filters, in the order the Owner listed them. «الكل» is no filter. */
export const FILTERS: ReadonlyArray<{ key: BellFilter; ar: string; en: string }> = [
  { key: 'all', ar: 'الكل', en: 'All' },
  { key: 'critical', ar: 'حرج', en: 'Critical' },
  { key: 'budget', ar: 'ميزانية', en: 'Budget' },
  { key: 'performance', ar: 'أداء', en: 'Performance' },
  { key: 'sync', ar: 'مزامنة', en: 'Sync' },
]
