import type { AgentAutonomy, AgentJob, SpecialistKey } from './api'

/**
 * AGENTS-ASSISTANT-001 — the words of the Agent Center, in one place. Short labels, plain Saudi-white Arabic, Latin digits.
 * The server sends codes; these are their words. An unknown code is shown as the code, never invented.
 */
type Pair = { ar: string; en: string }

export const SPECIALIST: Record<SpecialistKey, Pair> = {
  campaign: { ar: 'حملات', en: 'Campaigns' },
  analyst: { ar: 'تحليل', en: 'Analysis' },
  creative: { ar: 'إبداع', en: 'Creative' },
  leads: { ar: 'عملاء محتملون', en: 'Leads' },
  reports: { ar: 'تقارير', en: 'Reports' },
  builder: { ar: 'إنشاء', en: 'Build' },
}

export const JOB: Record<AgentJob, Pair> = {
  watch: { ar: 'متابعة', en: 'Watch' },
  analyse: { ar: 'تحليل', en: 'Analyse' },
  content: { ar: 'محتوى', en: 'Content' },
  reports: { ar: 'تقارير', en: 'Reports' },
  build: { ar: 'إنشاء', en: 'Build' },
}

export const AUTONOMY: Record<AgentAutonomy, Pair> = {
  suggests: { ar: 'يقترح فقط', en: 'Suggests only' },
  acts_after_approval: { ar: 'ينفّذ بعد موافقتك', en: 'Acts after your approval' },
}

const REASONS: Record<string, Pair> = {
  no_ai_credential: { ar: 'لا يوجد مفتاح ذكاء اصطناعي فعّال. أضفه من الإعدادات.', en: 'No active AI key. Add one in Settings.' },
  ai_token_limit_reached: { ar: 'بلغ المفتاح حدّه الشهري.', en: "The key reached its monthly limit." },
  // AI-PLATFORM-KEY-001 — this person's monthly replies on the platform key.
  ai_monthly_limit_reached: { ar: 'وصلت حدّك الشهري من ردود المساعد. يتجدد أول الشهر، أو ارفع الحد من الاشتراك.', en: 'You reached your monthly assistant limit. It renews on the 1st, or raise it from your subscription.' },
  ai_routine_needs_own_key: { ar: 'تشغيل الوكلاء المجدول يحتاج مفتاحًا خاصًا بالمساحة.', en: 'Scheduled agent runs need the workspace’s own key.' },
  daily_token_cap_reached: { ar: 'وصلت حدّك اليومي. يتجدد بعد منتصف الليل بتوقيت الرياض.', en: 'You reached your daily limit. It resets after midnight Riyadh time.' },
  thread_too_long: { ar: 'المحادثة طويلة جدًا. ابدأ محادثة جديدة.', en: 'This conversation is too long. Start a new one.' },
  model_not_configured: { ar: 'لم يُحدَّد نموذج لهذا المفتاح.', en: 'No model is set for this key.' },
  model_request_failed_401: { ar: 'رفض المزوّد المفتاح (401).', en: 'The provider refused the key (401).' },
  model_request_failed_429: { ar: 'تجاوز المفتاح حدّ المزوّد (429). حاول لاحقًا.', en: 'The key hit the provider rate limit (429).' },
  agent_turn_failed: { ar: 'تعذّر إكمال الرد. حاول مرة أخرى.', en: 'The reply could not be completed. Try again.' },
  needs_lead_sources: { ar: 'يحتاج ربط مصدر العملاء المحتملين أولًا.', en: 'Needs a lead source connected first.' },
  stopped: { ar: 'أوقفتَ الرد.', en: 'You stopped the reply.' },
  max_tool_rounds: { ar: 'توقف بعد أقصى عدد من الخطوات. اسأل بشكل أضيق.', en: 'Stopped after the maximum steps. Ask more narrowly.' },
  max_tokens: { ar: 'الرد طويل وقُطع. اطلب جزءًا أصغر.', en: 'The reply was cut for length.' },
  memory_rejected: { ar: 'لم يُحفظ: لا كلمات مرور أو مفاتيح أو أرقام تواصل.', en: 'Not saved: no passwords, keys or contact details.' },
  memory_full: { ar: 'الذاكرة ممتلئة.', en: 'Memory is full.' },
}

export function reasonText(code: string | null | undefined, ar: boolean): string {
  if (!code) return ''
  const w = REASONS[code]
  if (w) return ar ? w.ar : w.en
  const status = /^model_request_failed_(\d{3})$/.exec(code)?.[1]
  if (status) return ar ? `رفض المزوّد الطلب (${status}).` : `The provider refused the request (${status}).`
  return code
}

export const TOOL: Record<string, Pair> = {
  list_campaigns: { ar: 'الحملات', en: 'Campaigns' },
  get_history: { ar: 'السجل اليومي', en: 'Daily history' },
  compare_targets: { ar: 'مقارنة بالأهداف', en: 'Targets' },
  get_rules: { ar: 'قواعد العميل', en: 'Client rules' },
  list_creatives: { ar: 'الإعلانات', en: 'Ads' },
  propose_change: { ar: 'اقتراح تغيير', en: 'Proposed change' },
  list_automation_rules: { ar: 'القواعد التلقائية', en: 'Automated rules' },
  explain_rule_run: { ar: 'تشغيل قاعدة', en: 'Rule run' },
  draft_automation_rule: { ar: 'مسودة قاعدة', en: 'Draft rule' },
  launch_strategy: { ar: 'خطة الإطلاق', en: 'Launch strategy' },
  draft_launch_plan: { ar: 'مسودة حملة', en: 'Draft launch' },
  ask_agent: { ar: 'سؤال مختص', en: 'Asked a specialist' },
}

export const toolText = (name: string, ar: boolean): string => (TOOL[name] ? (ar ? TOOL[name].ar : TOOL[name].en) : name)

export const STEP: Record<string, Pair> = {
  routing: { ar: 'يحدد المختص…', en: 'Choosing a specialist…' },
  reading: { ar: 'يقرأ الأرقام…', en: 'Reading the figures…' },
  writing: { ar: 'يكتب…', en: 'Writing…' },
}

export const MODEL_LABEL: Record<string, string> = {
  'claude-sonnet-5-5': 'Claude Sonnet 5.5',
  'claude-opus-5-5': 'Claude Opus 5.5',
  'claude-haiku-5-5': 'Claude Haiku 5.5',
}
