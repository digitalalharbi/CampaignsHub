/**
 * REPORT-SETUP-001 — the canonical objectives and lead destinations a report is set up with.
 *
 * The provider's own word is kept internally; the setup screens offer six objectives and seven
 * destinations in the product's own words, whichever builder is open.
 */
export const CANONICAL_OBJECTIVES: Array<{ id: string; ar: string; en: string; members: string[] }> = [
  { id: 'awareness', ar: 'الوعي', en: 'Awareness', members: ['awareness', 'reach'] },
  { id: 'traffic', ar: 'الزيارات', en: 'Traffic', members: ['traffic', 'landing_page_views'] },
  { id: 'engagement', ar: 'التفاعل', en: 'Engagement', members: ['engagement', 'video_views'] },
  { id: 'leads', ar: 'العملاء المحتملون', en: 'Leads', members: ['leads'] },
  { id: 'sales', ar: 'المبيعات', en: 'Sales', members: ['sales', 'conversions', 'purchases', 'add_to_cart'] },
  { id: 'app', ar: 'التطبيق', en: 'App', members: ['app_installs'] },
]

export const DESTINATIONS: Array<{ id: string; ar: string; en: string; messaging: boolean }> = [
  { id: 'lead_form', ar: 'نموذج عملاء محتملين', en: 'Lead form', messaging: false },
  { id: 'website', ar: 'الموقع', en: 'Website', messaging: false },
  { id: 'whatsapp', ar: 'واتساب', en: 'WhatsApp', messaging: true },
  { id: 'messenger', ar: 'ماسنجر', en: 'Messenger', messaging: true },
  { id: 'instagram_direct', ar: 'رسائل إنستغرام', en: 'Instagram Direct', messaging: true },
  { id: 'calls', ar: 'اتصالات', en: 'Calls', messaging: false },
  { id: 'mixed', ar: 'وجهات متعددة', en: 'Mixed destinations', messaging: false },
]

/**
 * REPORT-DETAIL-LEVEL-001 — «مختصر», «قياسي», «تفصيلي»: how deep the report goes. Each is a different
 * document, not a different label (the server decides the form and the starting sections).
 */
export type DetailLevelKey = 'summary' | 'standard' | 'detailed'

export const DETAIL_LEVELS: Array<{
  key: DetailLevelKey
  ar: string
  en: string
  noteAr: string
  noteEn: string
}> = [
  { key: 'summary', ar: 'مختصر', en: 'Summary', noteAr: 'المؤشرات الرئيسية والاتجاه ومقارنة المنصات وأفضل محتوى وقراءة قصيرة', noteEn: 'Primary KPIs, trend, platform comparison, top content and a short reading' },
  { key: 'standard', ar: 'قياسي', en: 'Standard', noteAr: 'المختصر مع الأداء حسب الهدف وتفصيل الحملات والمحتوى', noteEn: 'The summary plus objective performance and the campaign and content breakdown' },
  { key: 'detailed', ar: 'تفصيلي', en: 'Detailed', noteAr: 'القياسي مع عمق كل منصة وتحليلات أعمق وأقسام محتوى موسّعة', noteEn: 'Standard plus per-platform depth, deeper analytics and expanded content' },
]

/** The two-valued form every existing renderer reads. */
export const formOf = (level: DetailLevelKey): 'executive_summary' | 'detailed' => (level === 'summary' ? 'executive_summary' : 'detailed')
