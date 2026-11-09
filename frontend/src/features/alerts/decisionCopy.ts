import type { AlertPriority, Bilingual, CellStatus, DecisionStatus } from './api'

/** OPERATIONAL-INTELLIGENCE-P1-001 — short labels, Arabic first. One place, so the board and the drawer say the same words. */
export const pick = (b: Bilingual | null | undefined, ar: boolean): string => (b ? (ar ? b.ar : b.en) : '')

export const CELL_LABEL: Record<CellStatus, Bilingual> = {
  healthy: { ar: 'سليم', en: 'Healthy' },
  watch: { ar: 'مراقبة', en: 'Watch' },
  problem: { ar: 'مشكلة', en: 'Problem' },
  no_data: { ar: 'لا بيانات', en: 'No data' },
}

/** A symbol beside the colour, so state never rests on colour alone. */
export const CELL_MARK: Record<CellStatus, string> = { healthy: '✓', watch: '◐', problem: '!', no_data: '—' }

export const CELL_TONE: Record<CellStatus, string> = {
  healthy: 'bg-success/10 text-success',
  watch: 'bg-warning/15 text-warning',
  problem: 'bg-danger/10 text-danger',
  no_data: 'bg-surface-hover text-text-muted',
}

export const PRIORITY_TONE: Record<AlertPriority, string> = {
  P1: 'bg-danger/10 text-danger',
  P2: 'bg-warning/15 text-warning',
  P3: 'bg-surface-hover text-text-secondary',
}

export const STATUS_LABEL: Record<DecisionStatus, Bilingual> = {
  new: { ar: 'جديد', en: 'New' },
  in_progress: { ar: 'قيد المعالجة', en: 'In progress' },
  done: { ar: 'تم', en: 'Done' },
  dismissed: { ar: 'تجاهل', en: 'Dismissed' },
}

export const ACTION_TAG_LABEL: Record<string, Bilingual> = {
  increase_budget: { ar: 'زيادة الميزانية', en: 'Increased budget' },
  decrease_budget: { ar: 'تخفيض الميزانية', en: 'Decreased budget' },
  pause_ad: { ar: 'إيقاف إعلان', en: 'Paused an ad' },
  change_content: { ar: 'تغيير المحتوى', en: 'Changed content' },
  adjust_audience: { ar: 'تعديل الجمهور', en: 'Adjusted audience' },
  fix_tracking: { ar: 'إصلاح التتبع', en: 'Fixed tracking' },
  reconnect_account: { ar: 'إعادة ربط الحساب', en: 'Reconnected account' },
  other: { ar: 'إجراء آخر', en: 'Other action' },
}

export const IMPACT_REASON: Record<string, Bilingual> = {
  window_incomplete: { ar: 'لم تكتمل فترة القياس بعد', en: 'The measurement window is not complete yet' },
  before_window_incomplete: { ar: 'بيانات ما قبل الإجراء ناقصة', en: 'Data before the action is incomplete' },
  incomplete_data: { ar: 'بيانات ما بعد الإجراء ناقصة', en: 'Data after the action is incomplete' },
  no_value_in_window: { ar: 'لا نتائج في فترة القياس', en: 'No results in the window' },
  not_a_campaign_metric: { ar: 'هذا التنبيه لا يقاس برقم حملة', en: 'This alert is not measured by a campaign figure' },
}
