import type { Proposal, ProposalStatus } from './api'

/**
 * The words for the gateway's codes. The server sends codes, never sentences, so the same refusal reads
 * the same in Arabic and English and a test can assert the rule that fired.
 */
const VIOLATIONS: Record<string, { ar: string; en: string }> = {
  action_not_allowed: { ar: 'هذا الإجراء غير مسموح به — يقتصر الوكلاء على الإيقاف والتشغيل وتعديل الميزانية اليومية', en: 'This action is not allowed — agents may only pause, resume or set a daily budget' },
  account_not_in_tenant: { ar: 'الحساب لا يتبع هذه المساحة', en: 'The account does not belong to this workspace' },
  account_not_bound_to_project: { ar: 'الحساب الإعلاني غير مربوط بهذا المشروع', en: 'The ad account is not bound to this project' },
  provider_mismatch: { ar: 'المنصة لا تطابق الحساب', en: 'The platform does not match the account' },
  target_type_unknown: { ar: 'نوع الهدف غير معروف', en: 'Unknown target type' },
  target_not_found: { ar: 'الحملة أو المجموعة غير موجودة في هذا المشروع', en: 'The campaign or ad set is not in this project' },
  budget_invalid: { ar: 'الميزانية غير صالحة — للإيقاف اطلب إيقافًا', en: 'Invalid budget — to stop spending, ask for a pause' },
  budget_target_not_supported: { ar: 'الميزانية تُعدَّل على الحملة أو المجموعة الإعلانية فقط', en: 'A budget is set on a campaign or ad set only' },
  increase_exceeds_cap: { ar: 'الزيادة أكبر من الحد المسموح لمرة واحدة', en: 'The raise is larger than the single-change cap' },
  above_daily_ceiling: { ar: 'الميزانية المقترحة فوق السقف اليومي لهذا المشروع', en: 'The proposed budget is above this project’s daily ceiling' },
  current_budget_unknown: { ar: 'الميزانية الحالية غير معروفة، فلا يمكن التحقق من الزيادة', en: 'The current budget is unknown, so a change cannot be checked' },
  execution_not_enabled_for_provider: { ar: 'التنفيذ غير مفعّل لهذه المنصة بعد — سُجّل القرار ولم يُرسل شيء إلى المنصة', en: 'Execution is not enabled for this platform yet — the decision was recorded and nothing was sent to the platform' },
}

export function violationText(code: string, ar: boolean): string {
  const w = VIOLATIONS[code]
  return w ? (ar ? w.ar : w.en) : code
}

export const STATUS_WORDS: Record<ProposalStatus, { ar: string; en: string; tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger' }> = {
  pending: { ar: 'بانتظار القرار', en: 'Waiting', tone: 'warning' },
  approved: { ar: 'معتمد', en: 'Approved', tone: 'info' },
  executing: { ar: 'قيد التنفيذ', en: 'Executing', tone: 'info' },
  executed: { ar: 'نُفّذ', en: 'Executed', tone: 'success' },
  failed: { ar: 'فشل التنفيذ', en: 'Failed', tone: 'danger' },
  rejected: { ar: 'مرفوض', en: 'Rejected', tone: 'neutral' },
  blocked: { ar: 'موقوف بقاعدة', en: 'Blocked', tone: 'danger' },
  expired: { ar: 'انتهت مهلته', en: 'Expired', tone: 'neutral' },
}

export function actionText(p: Pick<Proposal, 'action' | 'payload' | 'currency'>, ar: boolean): string {
  if (p.action === 'pause') return ar ? 'إيقاف' : 'Pause'
  if (p.action === 'resume') return ar ? 'تشغيل' : 'Resume'
  if (p.action === 'set_daily_budget') {
    const amount = p.payload?.daily_budget
    const money = amount === undefined ? '' : ` ${amount}${p.currency ? ` ${p.currency}` : ''}`
    return `${ar ? 'ميزانية يومية' : 'Daily budget'}${money}`
  }
  return p.action
}
