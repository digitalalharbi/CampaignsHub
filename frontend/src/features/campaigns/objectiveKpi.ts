import type { Locale } from '@/stores/ui'

/**
 * Derive a campaign's KPI set, funnel stage, report template and alert suggestions from the selected
 * objective's taxonomy metadata (campaign.objective options carry `{ kpi, funnel, template }`). The engine
 * guarantees the metadata is objective-appropriate — awareness/traffic/engagement never expose ROAS as their
 * primary KPI, and leads are never surfaced for awareness — so the primary KPI is simply the first metric.
 */
export interface DerivedKpi {
  primary: string | null
  secondary: string[]
  funnel: string | null
  template: string | null
  alerts: string[]
}

const FUNNEL_ALERTS: Record<string, string[]> = {
  awareness: ['frequency_high', 'no_delivery'],
  consideration: ['low_ctr', 'budget_pacing'],
  conversion: ['tracking_issue', 'cpa_spike', 'budget_risk'],
  custom: ['budget_risk'],
}

export function deriveKpi(metadata: Record<string, unknown> | null | undefined): DerivedKpi {
  const kpi = Array.isArray(metadata?.kpi) ? (metadata!.kpi as unknown[]).filter((k): k is string => typeof k === 'string') : []
  const funnel = typeof metadata?.funnel === 'string' ? (metadata!.funnel as string) : null
  const template = typeof metadata?.template === 'string' ? (metadata!.template as string) : null
  return {
    primary: kpi[0] ?? null,
    secondary: kpi.slice(1),
    funnel,
    template,
    alerts: funnel ? FUNNEL_ALERTS[funnel] ?? ['budget_risk'] : [],
  }
}

// ---- Bilingual labels (kept beside the feature, not in the global dictionary) ----
type L = Record<string, { ar: string; en: string }>

const KPI_LABELS: L = {
  reach: { ar: 'الوصول', en: 'Reach' }, impressions: { ar: 'الظهور', en: 'Impressions' },
  cpm: { ar: 'تكلفة الألف', en: 'CPM' }, frequency: { ar: 'التكرار', en: 'Frequency' },
  clicks: { ar: 'النقرات', en: 'Clicks' }, ctr: { ar: 'نسبة النقر', en: 'CTR' },
  cpc: { ar: 'تكلفة النقرة', en: 'CPC' }, sessions: { ar: 'الجلسات', en: 'Sessions' },
  engagements: { ar: 'التفاعلات', en: 'Engagements' }, eng_rate: { ar: 'معدل التفاعل', en: 'Engagement rate' },
  cpe: { ar: 'تكلفة التفاعل', en: 'CPE' }, leads: { ar: 'العملاء المحتملون', en: 'Leads' },
  cpl: { ar: 'تكلفة العميل المحتمل', en: 'CPL' }, conv_rate: { ar: 'معدل التحويل', en: 'Conversion rate' },
  installs: { ar: 'التثبيتات', en: 'Installs' }, cpi: { ar: 'تكلفة التثبيت', en: 'CPI' },
  install_rate: { ar: 'معدل التثبيت', en: 'Install rate' }, roas: { ar: 'العائد على الإنفاق', en: 'ROAS' },
  cpa: { ar: 'تكلفة الاكتساب', en: 'CPA' }, revenue: { ar: 'الإيرادات', en: 'Revenue' },
  conversions: { ar: 'التحويلات', en: 'Conversions' },
}

const FUNNEL_LABELS: L = {
  awareness: { ar: 'الوعي', en: 'Awareness' }, consideration: { ar: 'الاهتمام', en: 'Consideration' },
  conversion: { ar: 'التحويل', en: 'Conversion' }, custom: { ar: 'مخصص', en: 'Custom' },
}

const TEMPLATE_LABELS: L = {
  brand: { ar: 'تقرير العلامة', en: 'Brand' }, traffic: { ar: 'تقرير الزيارات', en: 'Traffic' },
  engagement: { ar: 'تقرير التفاعل', en: 'Engagement' }, lead_gen: { ar: 'تقرير العملاء المحتملين', en: 'Lead gen' },
  app: { ar: 'تقرير التطبيق', en: 'App' }, performance: { ar: 'تقرير الأداء', en: 'Performance' },
  custom: { ar: 'تقرير مخصص', en: 'Custom' },
}

const ALERT_LABELS: L = {
  frequency_high: { ar: 'تكرار مرتفع', en: 'High frequency' }, no_delivery: { ar: 'لا يوجد عرض', en: 'No delivery' },
  low_ctr: { ar: 'نسبة نقر منخفضة', en: 'Low CTR' }, budget_pacing: { ar: 'وتيرة الميزانية', en: 'Budget pacing' },
  tracking_issue: { ar: 'مشكلة تتبّع', en: 'Tracking issue' }, cpa_spike: { ar: 'ارتفاع تكلفة الاكتساب', en: 'CPA spike' },
  budget_risk: { ar: 'خطر ميزانية', en: 'Budget risk' },
}

const lookup = (map: L, key: string, locale: Locale): string => map[key]?.[locale] ?? key

export const kpiLabel = (key: string, locale: Locale) => lookup(KPI_LABELS, key, locale)
export const funnelLabel = (key: string, locale: Locale) => lookup(FUNNEL_LABELS, key, locale)
export const templateLabel = (key: string, locale: Locale) => lookup(TEMPLATE_LABELS, key, locale)
export const alertLabel = (key: string, locale: Locale) => lookup(ALERT_LABELS, key, locale)
