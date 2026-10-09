/** Status and priority labels + tone helpers shared by the internal requests dashboard and detail. */
export const STATUS_LABELS: Record<string, string> = {
  new: 'جديد',
  under_review: 'تحت المراجعة',
  waiting_client: 'ينتظر العميل',
  qualified: 'مؤهل',
  // REQ-JOURNEY-001 — the quote and hand-over steps the journey always had and the list did not.
  quoted: 'عرض سعر مُرسل',
  approved: 'معتمد',
  in_progress: 'قيد التنفيذ',
  delivered: 'تم التسليم',
  on_hold: 'معلّق',
  completed: 'مكتمل',
  rejected: 'مرفوض',
  cancelled: 'ملغى',
  archived: 'مؤرشف',
}

export function statusTone(status: string): string {
  switch (status) {
    case 'new': return 'bg-info/15 text-info'
    case 'quoted': return 'bg-purple/15 text-purple'
    case 'delivered': return 'bg-teal/15 text-teal'
    // A hold and «waiting for the client» are both pauses, and both read as amber.
    case 'waiting_client': case 'on_hold': return 'bg-warning/15 text-warning'
    case 'completed': case 'approved': case 'qualified': return 'bg-success/15 text-success'
    case 'rejected': case 'cancelled': return 'bg-danger/15 text-danger'
    case 'archived': return 'bg-surface-secondary text-text-muted'
    default: return 'bg-brand-primary-soft text-brand-700'
  }
}

export function priorityTone(priority: string): string {
  switch (priority) {
    case 'critical': return 'bg-danger/15 text-danger'
    case 'high': return 'bg-warning/15 text-warning'
    case 'low': return 'bg-surface-secondary text-text-muted'
    default: return 'bg-info/15 text-info'
  }
}

/**
 * REQ-DETAIL-LABELS-001 — the status and priority CHOICES in the reader's language.
 *
 * The header badges already read `status_label_en` from the server (REQ-LABELS-001), but the two
 * selects beside them offered Arabic-only statuses and raw `critical / high / medium / low` tokens
 * to every reader. The server's own English status names are not available for the option list, so
 * the English half lives here, beside the Arabic half it mirrors.
 */
const STATUS_LABELS_EN: Record<string, string> = {
  new: 'New',
  under_review: 'Under review',
  waiting_client: 'Waiting on client',
  qualified: 'Qualified',
  quoted: 'Quote sent',
  approved: 'Approved',
  in_progress: 'In progress',
  delivered: 'Delivered',
  on_hold: 'On hold',
  completed: 'Completed',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
  archived: 'Archived',
}

export function statusLabel(status: string, lang: 'ar' | 'en'): string {
  return (lang === 'ar' ? STATUS_LABELS[status] : STATUS_LABELS_EN[status]) ?? status
}

const PRIORITY_LABELS: Record<string, { ar: string; en: string }> = {
  critical: { ar: 'حرجة', en: 'Critical' },
  high: { ar: 'عالية', en: 'High' },
  medium: { ar: 'متوسطة', en: 'Medium' },
  low: { ar: 'منخفضة', en: 'Low' },
}

export function priorityLabel(priority: string, lang: 'ar' | 'en'): string {
  return PRIORITY_LABELS[priority]?.[lang] ?? priority
}
