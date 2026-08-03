/** Arabic status labels + tone helpers shared by the internal requests dashboard and detail. */
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
