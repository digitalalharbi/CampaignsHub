import type { BudgetStatus, PaymentMode } from './fundingApi'
import { moneyNumber } from './money'

/**
 * FIN-LOOP-001 — every word on the budgets surfaces, from the style guide (Owner 2026-11-13):
 * الميزانية · الإنفاق · المتبقي, short verbs, no explanatory text.
 */
export const L = {
  title: { ar: 'الميزانيات', en: 'Budgets' },
  add: { ar: 'أضف ميزانية', en: 'Add budget' },
  renew: { ar: 'جدّد الميزانية', en: 'Renew' },
  approved: { ar: 'الميزانية', en: 'Budget' },
  spent: { ar: 'الإنفاق', en: 'Spent' },
  remaining: { ar: 'المتبقي', en: 'Remaining' },
  alerts: { ar: 'تنبيهات', en: 'Alerts' },
  pace: { ar: 'سرعة الصرف', en: 'Pace' },
  perDay: { ar: '/يوم', en: '/day' },
  daysLeft: { ar: 'يكفي تقريبًا', en: 'About' },
  days: { ar: 'يوم', en: 'days' },
  client: { ar: 'العميل', en: 'Client' },
  project: { ar: 'المشروع', en: 'Project' },
  amount: { ar: 'المبلغ', en: 'Amount' },
  date: { ar: 'التاريخ', en: 'Date' },
  reference: { ar: 'المرجع', en: 'Reference' },
  addAmount: { ar: (n: string) => `أضف ${n} ر.س`, en: (n: string) => `Add SAR ${n}` },
  history: { ar: 'الدفعات', en: 'Payments' },
  platforms: { ar: 'المنصات', en: 'Platforms' },
  month: { ar: 'ملخص الشهر', en: 'Month summary' },
  fixed: { ar: 'ثابت', en: 'Fixed' },
  live: { ar: 'مباشر', en: 'Live' },
  added: { ar: 'المضاف', en: 'Added' },
  start: { ar: 'أول الشهر', en: 'Start' },
  end: { ar: 'آخر الشهر', en: 'End' },
  export: { ar: 'تصدير', en: 'Export' },
  exportFailed: { ar: 'تعذّر التصدير', en: 'Export failed' },
  empty: { ar: 'ما فيه ميزانيات بعد', en: 'No budgets yet' },
  spendUpTo: { ar: 'الإنفاق حتى', en: 'Spend up to' },
  sar: { ar: 'ر.س', en: 'SAR' },
  edit: { ar: 'عدّل', en: 'Edit' },
  del: { ar: 'احذف', en: 'Delete' },
  undo: { ar: 'تراجع', en: 'Undo' },
  save: { ar: 'احفظ', en: 'Save' },
  cancel: { ar: 'إلغاء', en: 'Cancel' },
  reason: { ar: 'السبب', en: 'Reason' },
  note: { ar: 'ملاحظة', en: 'Note' },
  period: { ar: 'الفترة', en: 'Period' },
  from: { ar: 'من', en: 'From' },
  to: { ar: 'إلى', en: 'To' },
  open: { ar: 'مفتوحة', en: 'Open' },
  total: { ar: 'المجموع', en: 'Total' },
  deleted: { ar: 'المحذوفة', en: 'Deleted' },
  back: { ar: 'الميزانيات', en: 'Budgets' },
  close: { ar: 'إغلاق', en: 'Close' },
  done: { ar: 'تم', en: 'Done' },
  undone: { ar: 'تم التراجع', en: 'Undone' },
  undoFailed: { ar: 'تعذّر التراجع', en: 'Undo failed' },
  delTitle: { ar: 'حذف الميزانية', en: 'Delete budget' },
  delDone: { ar: 'حُذفت الميزانية', en: 'Budget deleted' },
  editDone: { ar: 'تم التعديل', en: 'Budget edited' },
  addDone: { ar: 'أُضيفت الميزانية', en: 'Budget added' },
  noRate: { ar: 'بدون سعر صرف', en: 'No FX rate' },
  pdfOff: { ar: 'PDF غير مفعّل', en: 'PDF is off' },
  withHistory: { ar: 'مع السجل', en: 'With history' },
  spentToDate: { ar: 'الإنفاق حتى الآن', en: 'Spent so far' },
  thisMonth: { ar: 'هذا الشهر (للفوترة)', en: 'This month (to invoice)' },
  periodChip: { ar: 'الفترة', en: 'Period' },
  plusCeiling: { ar: '+ سقف', en: '+ Ceiling' },
  ceiling: { ar: 'سقف', en: 'Ceiling' },
  ceilingOptional: { ar: 'سقف (اختياري)', en: 'Ceiling (optional)' },
  noCeiling: { ar: 'بلا سقف', en: 'No ceiling' },
  invoiceMonths: { ar: 'المستحق بالشهر', en: 'To invoice by month' },
  monthClosed: { ar: 'مكتمل', en: 'Closed' },
  monthRunning: { ar: 'جارٍ', en: 'Running' },
  plan: { ar: 'الخطة', en: 'Plan' },
  planVsActual: { ar: 'الخطة والفعلي', en: 'Plan vs actual' },
  planDiff: { ar: 'فرق عن الخطة', en: 'Off plan' },
  planned: { ar: 'المخطط', en: 'Planned' },
  projected: { ar: 'المتوقع', en: 'Projected' },
  realloc: { ar: 'أعد التوزيع', en: 'Reallocate' },
  reallocAuto: { ar: 'إعادة توزيع حسب الصرف', en: 'Rebalanced by spend' },
  reallocDone: { ar: 'تمت إعادة التوزيع', en: 'Reallocated' },
  payMode: { ar: 'الطريقة', en: 'Mode' },
  addPlatform: { ar: 'أضف منصة', en: 'Add platform' },
  platform: { ar: 'المنصة', en: 'Platform' },
  planSum: { ar: 'المجموع', en: 'Sum' },
  byPercent: { ar: 'نسبة', en: '%' },
  byAmount: { ar: 'مبلغ', en: 'Amount' },
  periodFirstSpend: { ar: 'من أول إنفاق', en: 'From first spend' },
} as const

export const KIND: Record<'initial' | 'top_up' | 'adjustment' | 'void' | 'reversal' | 'reallocation', { ar: string; en: string }> = {
  initial: { ar: 'ميزانية', en: 'Budget' },
  top_up: { ar: 'تجديد', en: 'Renewal' },
  adjustment: { ar: 'تعديل', en: 'Edit' },
  void: { ar: 'حذف', en: 'Delete' },
  reversal: { ar: 'تراجع', en: 'Undo' },
  reallocation: { ar: 'إعادة توزيع', en: 'Reallocation' },
}

export const STATUS: Record<BudgetStatus, { ar: string; en: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }> = {
  ok: { ar: 'ضمن الميزانية', en: 'On track', tone: 'success' },
  low: { ar: 'قرّبت تخلص', en: 'Running low', tone: 'warning' },
  over: { ar: 'تجاوزت الميزانية', en: 'Over budget', tone: 'danger' },
  incomplete: { ar: 'ناقص سعر صرف', en: 'Missing FX rate', tone: 'warning' },
  no_budget: { ar: 'بدون ميزانية', en: 'No budget', tone: 'neutral' },
  direct: { ar: 'دفع مباشر', en: 'Direct pay', tone: 'neutral' },
  unset: { ar: 'لم يُحدد', en: 'Not set', tone: 'neutral' },
  void: { ar: 'محذوفة', en: 'Deleted', tone: 'neutral' },
  credit: { ar: 'بالأجل', en: 'On credit', tone: 'neutral' },
  credit_near: { ar: 'قرّبت من السقف', en: 'Near ceiling', tone: 'warning' },
  credit_over: { ar: 'تجاوزت السقف', en: 'Over ceiling', tone: 'danger' },
}

/** The mode chip on a row: مباشر / بالأجل, and مخطّطة when the budget has a plan per platform. */
export const MODE: Record<PaymentMode | 'planned', { ar: string; en: string }> = {
  direct: { ar: 'مباشر', en: 'Direct' },
  credit: { ar: 'بالأجل', en: 'Credit' },
  planned: { ar: 'مخطّطة', en: 'Planned' },
}

/** The ONE plan chip: «فرق عن الخطة». It states that a platform is off its share, never why; red only when it is over. */
export const PLAN_DIFF: { ar: string; en: string } = { ar: 'فرق عن الخطة', en: 'Off plan' }

export const TONE_BAR: Record<'success' | 'warning' | 'danger' | 'neutral', string> = {
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  neutral: 'bg-border-strong',
}

/** Whole riyals with Latin digits, the product's numeral rule. */
export function sar(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return moneyNumber(n)
}
