import type { Locale } from '@/stores/ui'

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

const status: Record<string, { ar: string; en: string; tone: Tone }> = {
  draft: { ar: 'مسودة', en: 'Draft', tone: 'neutral' },
  active: { ar: 'نشطة', en: 'Active', tone: 'success' },
  paused: { ar: 'موقوفة', en: 'Paused', tone: 'warning' },
  completed: { ar: 'مكتملة', en: 'Completed', tone: 'info' },
  archived: { ar: 'مؤرشفة', en: 'Archived', tone: 'neutral' },
  pending: { ar: 'قيد المراجعة', en: 'Pending', tone: 'warning' },
  unknown: { ar: 'غير معروفة', en: 'Unknown', tone: 'neutral' },
}

const objective: Record<string, { ar: string; en: string }> = {
  awareness: { ar: 'الوعي', en: 'Awareness' },
  traffic: { ar: 'الزيارات', en: 'Traffic' },
  engagement: { ar: 'التفاعل', en: 'Engagement' },
  leads: { ar: 'العملاء المحتملون', en: 'Leads' },
  app_installs: { ar: 'تثبيت التطبيق', en: 'App installs' },
  sales: { ar: 'المبيعات', en: 'Sales' },
  conversions: { ar: 'التحويلات', en: 'Conversions' },
  other: { ar: 'أخرى', en: 'Other' },
}

const provider: Record<string, { ar: string; en: string }> = {
  meta: { ar: 'ميتا', en: 'Meta' },
  google: { ar: 'جوجل', en: 'Google Ads' },
  tiktok: { ar: 'تيك توك', en: 'TikTok' },
  snapchat: { ar: 'سناب شات', en: 'Snapchat' },
  x: { ar: 'إكس', en: 'X' },
  linkedin: { ar: 'لينكدإن', en: 'LinkedIn' },
  microsoft: { ar: 'مايكروسوفت', en: 'Microsoft' },
  pinterest: { ar: 'بنترست', en: 'Pinterest' },
  sandbox: { ar: 'Sandbox', en: 'Sandbox' },
}

// Internal (team-facing) classification — persisted, editable, filterable, audited.
const stage: Record<string, { ar: string; en: string; tone: Tone }> = {
  planning: { ar: 'تخطيط', en: 'Planning', tone: 'neutral' },
  setup: { ar: 'إعداد', en: 'Setup', tone: 'neutral' },
  learning: { ar: 'تعلّم', en: 'Learning', tone: 'info' },
  scaling: { ar: 'توسّع', en: 'Scaling', tone: 'info' },
  optimization: { ar: 'تحسين', en: 'Optimization', tone: 'info' },
  stable: { ar: 'مستقرة', en: 'Stable', tone: 'success' },
  declining: { ar: 'متراجعة', en: 'Declining', tone: 'warning' },
  completed: { ar: 'مكتملة', en: 'Completed', tone: 'neutral' },
  archived: { ar: 'مؤرشفة', en: 'Archived', tone: 'neutral' },
}

const performance: Record<string, { ar: string; en: string; tone: Tone }> = {
  top_performing: { ar: 'أداء متميز', en: 'Top Performing', tone: 'success' },
  on_track: { ar: 'على المسار', en: 'On Track', tone: 'success' },
  needs_optimization: { ar: 'تحتاج تحسينًا', en: 'Needs Optimization', tone: 'warning' },
  budget_risk: { ar: 'خطر ميزانية', en: 'Budget Risk', tone: 'danger' },
  no_results: { ar: 'بلا نتائج', en: 'No Results', tone: 'danger' },
  tracking_issue: { ar: 'مشكلة تتبّع', en: 'Tracking Issue', tone: 'danger' },
  stale_data: { ar: 'بيانات قديمة', en: 'Stale Data', tone: 'warning' },
}

const priority: Record<string, { ar: string; en: string; tone: Tone }> = {
  critical: { ar: 'حرجة', en: 'Critical', tone: 'danger' },
  high: { ar: 'عالية', en: 'High', tone: 'warning' },
  medium: { ar: 'متوسطة', en: 'Medium', tone: 'info' },
  low: { ar: 'منخفضة', en: 'Low', tone: 'neutral' },
}

export const STAGE_KEYS = Object.keys(stage)
export const PERFORMANCE_KEYS = Object.keys(performance)
export const PRIORITY_KEYS = Object.keys(priority)

export function stageLabel(key: string | null | undefined, locale: Locale): string {
  return key ? (stage[key]?.[locale] ?? key) : ''
}
export function stageTone(key: string | null | undefined): Tone {
  return (key && stage[key]?.tone) || 'neutral'
}
export function performanceLabel(key: string | null | undefined, locale: Locale): string {
  return key ? (performance[key]?.[locale] ?? key) : ''
}
export function performanceTone(key: string | null | undefined): Tone {
  return (key && performance[key]?.tone) || 'neutral'
}
export function priorityLabel(key: string | null | undefined, locale: Locale): string {
  return key ? (priority[key]?.[locale] ?? key) : ''
}
export function priorityTone(key: string | null | undefined): Tone {
  return (key && priority[key]?.tone) || 'neutral'
}

export function campaignStatusLabel(key: string, locale: Locale): string {
  return status[key]?.[locale] ?? key
}

export function campaignStatusTone(key: string): Tone {
  return status[key]?.tone ?? 'neutral'
}

export function objectiveLabel(key: string, locale: Locale): string {
  return objective[key]?.[locale] ?? key
}

export function providerLabel(key: string, locale: Locale): string {
  return provider[key]?.[locale] ?? key
}
