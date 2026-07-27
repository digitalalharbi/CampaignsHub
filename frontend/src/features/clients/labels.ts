export type Lang = 'ar' | 'en'

/** Bilingual labels for client taxonomy enums (kept beside the feature, not in the global dictionary). */
type Map = Record<string, { ar: string; en: string }>

export const CLIENT_STATUS_LABELS: Map = {
  prospect: { ar: 'مُحتمَل', en: 'Prospect' },
  onboarding: { ar: 'قيد التهيئة', en: 'Onboarding' },
  active: { ar: 'نشِط', en: 'Active' },
  needs_attention: { ar: 'يحتاج متابعة', en: 'Needs Attention' },
  paused: { ar: 'متوقّف مؤقتًا', en: 'Paused' },
  completed: { ar: 'مكتمل', en: 'Completed' },
  archived: { ar: 'مؤرشف', en: 'Archived' },
}

export const SERVICE_LEVEL_LABELS: Map = {
  managed_service: { ar: 'إدارة كاملة', en: 'Managed Service' },
  consulting: { ar: 'استشارات', en: 'Consulting' },
  reporting_only: { ar: 'تقارير فقط', en: 'Reporting Only' },
  analytics_only: { ar: 'تحليلات فقط', en: 'Analytics Only' },
  self_service: { ar: 'خدمة ذاتية', en: 'Self-Service' },
}

export const INDUSTRY_LABELS: Map = {
  e_commerce: { ar: 'تجارة إلكترونية', en: 'E-commerce' },
  lead_generation: { ar: 'جذب عملاء محتملين', en: 'Lead Generation' },
  mobile_app: { ar: 'تطبيق جوّال', en: 'Mobile App' },
  b2b: { ar: 'شركات (B2B)', en: 'B2B' },
  real_estate: { ar: 'عقارات', en: 'Real Estate' },
  education: { ar: 'تعليم', en: 'Education' },
  healthcare: { ar: 'رعاية صحية', en: 'Healthcare' },
  events: { ar: 'فعاليات', en: 'Events' },
  local_business: { ar: 'نشاط محلي', en: 'Local Business' },
  government: { ar: 'قطاع حكومي', en: 'Government' },
  custom: { ar: 'مخصّص', en: 'Custom' },
}

export const PRIORITY_LABELS: Map = {
  low: { ar: 'منخفضة', en: 'Low' },
  normal: { ar: 'عادية', en: 'Normal' },
  high: { ar: 'عالية', en: 'High' },
}

export const ACCESS_ROLE_LABELS: Map = {
  client_owner: { ar: 'مسؤول العميل', en: 'Client Owner' },
  media_buyer: { ar: 'مشتري إعلانات', en: 'Media Buyer' },
  analyst: { ar: 'محلل', en: 'Analyst' },
  reporter: { ar: 'معدّ تقارير', en: 'Reporter' },
  viewer: { ar: 'مُطّلع', en: 'Viewer' },
  custom: { ar: 'مخصّص', en: 'Custom' },
}

export function labelOf(map: Map, key: string | null | undefined, lang: Lang): string {
  if (!key) return '—'
  return map[key]?.[lang] ?? key
}
