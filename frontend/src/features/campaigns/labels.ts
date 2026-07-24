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
