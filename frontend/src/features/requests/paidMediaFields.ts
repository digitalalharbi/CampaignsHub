import { sortByPlatform } from '@/lib/platforms'
/**
 * Dynamic paid-media intake — the `metadata.needs` token → field mapping.
 *
 * The set of services a visitor selects (engine-managed taxonomy) exposes a deduped union of `needs`
 * tokens (see `mergedNeeds` in ../paid-media/publicCatalog). Each token maps to EXACTLY ONE labelled
 * field here, so shared questions across services are asked once. This file carries only field
 * definitions + local bilingual copy (never a hardcoded service/category list — those come from the
 * catalog). Localised inside the requests feature, not the global i18n.
 */

/** Logical step buckets the dynamic fields are grouped into. */
export type PaidFieldGroup = 'brief' | 'tracking' | 'content'

/** How a token is rendered. */
export type PaidControl =
  | 'text'
  | 'url'
  | 'textarea'
  | 'budget'
  | 'select'
  | 'multi'
  | 'tags'
  | 'date'
  | 'datetime'
  | 'files'

export interface PaidFieldOption {
  value: string
  ar: string
  en: string
}

export interface PaidFieldDef {
  token: string
  labelAr: string
  labelEn: string
  control: PaidControl
  group: PaidFieldGroup
  /** For select/multi controls. */
  options?: PaidFieldOption[]
  hintAr?: string
  hintEn?: string
}

/**
 * Platform pick-list (a field value enum — NOT a taxonomy service list).
 *
 * In the product's order (PLATFORM-ORDER-001), so the visitor filing a request meets the six
 * platforms in the same sequence the dashboard, the connection centre and every report will use.
 */
export const PAID_PLATFORMS: PaidFieldOption[] = sortByPlatform([
  { value: 'snapchat', ar: 'سناب شات', en: 'Snapchat' },
  { value: 'tiktok', ar: 'تيك توك', en: 'TikTok' },
  { value: 'meta', ar: 'ميتا (فيسبوك/إنستغرام)', en: 'Meta (Facebook/Instagram)' },
  { value: 'google', ar: 'جوجل', en: 'Google' },
  { value: 'x', ar: 'إكس (تويتر)', en: 'X (Twitter)' },
  { value: 'linkedin', ar: 'لينكدإن', en: 'LinkedIn' },
], (o) => o.value)

const OBJECTIVES: PaidFieldOption[] = [
  { value: 'sales', ar: 'المبيعات', en: 'Sales' },
  { value: 'leads', ar: 'العملاء المحتملون', en: 'Leads' },
  { value: 'awareness', ar: 'الوعي والوصول', en: 'Awareness & reach' },
  { value: 'traffic', ar: 'الزيارات', en: 'Traffic' },
  { value: 'engagement', ar: 'التفاعل', en: 'Engagement' },
  { value: 'app_installs', ar: 'تثبيت التطبيق', en: 'App installs' },
  { value: 'conversions', ar: 'التحويلات', en: 'Conversions' },
]

const STORE_OR_APP: PaidFieldOption[] = [
  { value: 'store', ar: 'متجر إلكتروني', en: 'Online store' },
  { value: 'app', ar: 'تطبيق جوال', en: 'Mobile app' },
  { value: 'website', ar: 'موقع إلكتروني', en: 'Website' },
  { value: 'both', ar: 'متجر وتطبيق', en: 'Store & app' },
]

const LANGUAGES: PaidFieldOption[] = [
  { value: 'ar', ar: 'العربية', en: 'Arabic' },
  { value: 'en', ar: 'الإنجليزية', en: 'English' },
  { value: 'both', ar: 'العربية والإنجليزية', en: 'Arabic & English' },
]

const REPORT_FORMATS: PaidFieldOption[] = [
  { value: 'pdf', ar: 'PDF', en: 'PDF' },
  { value: 'xlsx', ar: 'Excel', en: 'Excel' },
  { value: 'csv', ar: 'CSV', en: 'CSV' },
  { value: 'slides', ar: 'عرض تقديمي', en: 'Slides' },
]

const PERIODS: PaidFieldOption[] = [
  { value: 'last_7', ar: 'آخر 7 أيام', en: 'Last 7 days' },
  { value: 'last_30', ar: 'آخر 30 يومًا', en: 'Last 30 days' },
  { value: 'last_90', ar: 'آخر 90 يومًا', en: 'Last 90 days' },
  { value: 'this_quarter', ar: 'هذا الربع', en: 'This quarter' },
  { value: 'custom', ar: 'فترة مخصصة', en: 'Custom period' },
]

/*
 * Data sources: the ad platforms first, in the product's order, then everything else.
 *
 * `sortByPlatform` is stable, so GA4, the CRM, Salla and Zid — none of which are ad platforms and all
 * of which rank equal — keep the order written here rather than being shuffled between renders.
 */
const DATA_SOURCES: PaidFieldOption[] = sortByPlatform([
  { value: 'snapchat', ar: 'سناب شات', en: 'Snapchat' },
  { value: 'tiktok', ar: 'تيك توك', en: 'TikTok' },
  { value: 'meta', ar: 'ميتا', en: 'Meta' },
  { value: 'google_ads', ar: 'إعلانات جوجل', en: 'Google Ads' },
  { value: 'ga4', ar: 'GA4', en: 'GA4' },
  { value: 'crm', ar: 'نظام CRM', en: 'CRM' },
  { value: 'salla', ar: 'سلة', en: 'Salla' },
  { value: 'zid', ar: 'زد', en: 'Zid' },
], (o) => o.value)

/**
 * The full needs → field map. A token missing here is simply ignored (unknown needs never crash the
 * form). Order within this array is the visual order inside each group step.
 */
export const PAID_FIELD_DEFS: PaidFieldDef[] = [
  // ---- Brief: campaign, objective, platforms, budget, period ----
  { token: 'objective', labelAr: 'هدف الحملة', labelEn: 'Campaign objective', control: 'select', group: 'brief', options: OBJECTIVES },
  { token: 'platform', labelAr: 'المنصة', labelEn: 'Platform', control: 'select', group: 'brief', options: PAID_PLATFORMS },
  { token: 'platforms', labelAr: 'المنصات', labelEn: 'Platforms', control: 'multi', group: 'brief', options: PAID_PLATFORMS },
  { token: 'budget', labelAr: 'الميزانية', labelEn: 'Budget', control: 'budget', group: 'brief' },
  { token: 'period', labelAr: 'الفترة الزمنية', labelEn: 'Period', control: 'select', group: 'brief', options: PERIODS },
  { token: 'regions', labelAr: 'المناطق المستهدفة', labelEn: 'Target regions', control: 'tags', group: 'brief' },
  { token: 'audience', labelAr: 'الجمهور المستهدف', labelEn: 'Target audience', control: 'text', group: 'brief' },
  { token: 'store_or_app', labelAr: 'المتجر / التطبيق', labelEn: 'Store / app', control: 'select', group: 'brief', options: STORE_OR_APP },
  { token: 'language', labelAr: 'اللغة', labelEn: 'Language', control: 'select', group: 'brief', options: LANGUAGES },
  { token: 'kpis', labelAr: 'مؤشرات الأداء', labelEn: 'KPIs', control: 'tags', group: 'brief' },

  // ---- Tracking & integrations ----
  { token: 'site_url', labelAr: 'الموقع الإلكتروني', labelEn: 'Website URL', control: 'url', group: 'tracking' },
  { token: 'gtm', labelAr: 'Google Tag Manager', labelEn: 'Google Tag Manager', control: 'text', group: 'tracking', hintAr: 'معرّف الحاوية أو حالة الإعداد', hintEn: 'Container ID or setup status' },
  { token: 'events', labelAr: 'أحداث التحويل المطلوبة', labelEn: 'Required conversion events', control: 'tags', group: 'tracking' },
  { token: 'data_sources', labelAr: 'مصادر البيانات', labelEn: 'Data sources', control: 'multi', group: 'tracking', options: DATA_SOURCES },
  { token: 'accounts', labelAr: 'الحسابات الإعلانية', labelEn: 'Ad accounts', control: 'textarea', group: 'tracking', hintAr: 'أسماء/أرقام الحسابات أو حالتها', hintEn: 'Account names/ids or their status' },

  // ---- Content, files & notes ----
  { token: 'topic', labelAr: 'الموضوع', labelEn: 'Topic', control: 'text', group: 'content' },
  { token: 'creatives', labelAr: 'المحتويات / الإبداعات', labelEn: 'Creatives', control: 'textarea', group: 'content', hintAr: 'صف المحتوى المتاح أو المطلوب', hintEn: 'Describe available or needed creative' },
  { token: 'assets', labelAr: 'الأصول المتاحة', labelEn: 'Available assets', control: 'textarea', group: 'content' },
  { token: 'funnel', labelAr: 'القمع التسويقي', labelEn: 'Marketing funnel', control: 'textarea', group: 'content' },
  { token: 'current_performance', labelAr: 'الأداء الحالي', labelEn: 'Current performance', control: 'textarea', group: 'content', hintAr: 'مثل CPA / CPL / ROAS الحالية', hintEn: 'e.g. current CPA / CPL / ROAS' },
  { token: 'challenges', labelAr: 'التحديات', labelEn: 'Challenges', control: 'textarea', group: 'content' },
  { token: 'previous_reports', labelAr: 'تقارير سابقة', labelEn: 'Previous reports', control: 'textarea', group: 'content', hintAr: 'صف التقارير السابقة أو أرفقها في الملفات', hintEn: 'Describe prior reports or attach them below' },
  { token: 'format', labelAr: 'صيغة التقرير', labelEn: 'Report format', control: 'select', group: 'content', options: REPORT_FORMATS },
  { token: 'schedule', labelAr: 'الموعد المفضل', labelEn: 'Preferred schedule', control: 'datetime', group: 'content' },
  { token: 'files', labelAr: 'ملفات', labelEn: 'Files', control: 'files', group: 'content' },
]

const FIELD_BY_TOKEN = new Map(PAID_FIELD_DEFS.map((f) => [f.token, f]))

/**
 * Resolve a deduped list of needs tokens into ordered field defs (unknown tokens dropped). Each token
 * yields at most one field, so the union of several services' needs renders every shared question once.
 */
export function fieldsForNeeds(needs: string[]): PaidFieldDef[] {
  const wanted = new Set(needs)
  // Iterate PAID_FIELD_DEFS (stable visual order) and keep only requested tokens — deduped by construction.
  return PAID_FIELD_DEFS.filter((f) => wanted.has(f.token))
}

/** Fields of a given group, in visual order, for the current needs set. */
export function groupFields(needs: string[], group: PaidFieldGroup): PaidFieldDef[] {
  return fieldsForNeeds(needs).filter((f) => f.group === group)
}

export function fieldDef(token: string): PaidFieldDef | undefined {
  return FIELD_BY_TOKEN.get(token)
}

/** Bilingual copy local to the paid-media intake (no global i18n keys touched). */
export const PAID_COPY = {
  ar: {
    heading: 'طلب خدمة إعلانية',
    intro: 'اختر الخدمات التي تحتاجها وأكمل التفاصيل، وسنراجع طلبك ونتواصل معك عبر رابط تتبع آمن.',
    stepServices: 'الخدمات',
    stepApplicant: 'مقدّم الطلب',
    stepBrief: 'الحملة والأهداف',
    stepTracking: 'التتبع والتكاملات',
    stepContent: 'المحتوى والملفات',
    stepReview: 'المراجعة',
    servicesTitle: 'الخدمات المطلوبة',
    servicesEmpty: 'لم تُحدَّد أي خدمة بعد — أضف خدمة للمتابعة.',
    selectedCount: 'الخدمات المختارة',
    editServices: 'أضف خدمة / تعديل الخدمات',
    perServiceNote: 'ملاحظة على الخدمة (اختياري)',
    customTitle: 'وصف الطلب المخصص',
    customLabel: 'اشرح ما تحتاجه بالتفصيل',
    errCustom: 'اكتب وصفًا للطلب المخصص',
    retry: 'إعادة المحاولة',
    applicantTitle: 'معلومات مقدّم الطلب',
    name: 'الاسم',
    email: 'البريد الإلكتروني',
    phone: 'رقم الجوال (مع رمز الدولة)',
    company: 'اسم النشاط أو الشركة',
    briefTitle: 'تفاصيل الحملة',
    trackingTitle: 'التتبع والتكاملات',
    contentTitle: 'المحتوى والملفات والملاحظات',
    notes: 'ملاحظات إضافية',
    attachments: 'المرفقات',
    chooseFiles: 'اختر ملفات للرفع',
    filesHint: 'PDF، صور، Excel، CSV — حتى 10MB لكل ملف',
    reviewTitle: 'مراجعة الطلب',
    verifyTitle: 'تحقّق من وسيلة التواصل',
    services: 'الخدمات',
    none: 'لا يوجد',
    next: 'التالي',
    back: 'السابق',
    submit: 'إرسال الطلب',
    currency: 'العملة',
    waitUploads: 'انتظر اكتمال رفع الملفات قبل المتابعة',
    errName: 'الاسم مطلوب',
    errEmail: 'بريد غير صحيح',
    errPhone: 'أدخل رقمًا دوليًا مع رمز الدولة (مثال ‎+9665...)',
    errCompany: 'اسم المنشأة مطلوب',
    errServices: 'أضف خدمة واحدة على الأقل',
    loadError: 'تعذّر تحميل الخدمات، حدّث الصفحة.',
    unknownDropped: 'تم تجاهل خدمات غير معروفة من الرابط.',
  },
  en: {
    heading: 'Paid-media service request',
    intro: 'Pick the services you need and complete the details; we’ll review your request and follow up via a secure tracking link.',
    stepServices: 'Services',
    stepApplicant: 'Applicant',
    stepBrief: 'Campaign & objectives',
    stepTracking: 'Tracking & integrations',
    stepContent: 'Content & files',
    stepReview: 'Review',
    servicesTitle: 'Requested services',
    servicesEmpty: 'No service selected yet — add a service to continue.',
    selectedCount: 'Selected services',
    editServices: 'Add / edit services',
    perServiceNote: 'Note for this service (optional)',
    customTitle: 'Custom request details',
    customLabel: 'Explain what you need in detail',
    errCustom: 'Describe your custom request',
    retry: 'Retry',
    applicantTitle: 'Applicant information',
    name: 'Name',
    email: 'Email',
    phone: 'Phone (with country code)',
    company: 'Company',
    briefTitle: 'Campaign details',
    trackingTitle: 'Tracking & integrations',
    contentTitle: 'Content, files & notes',
    notes: 'Additional notes',
    attachments: 'Attachments',
    chooseFiles: 'Choose files to upload',
    filesHint: 'PDF, images, Excel, CSV — up to 10MB each',
    reviewTitle: 'Review your request',
    verifyTitle: 'Verify your contact',
    services: 'Services',
    none: 'None',
    next: 'Next',
    back: 'Back',
    submit: 'Submit request',
    currency: 'Currency',
    waitUploads: 'Wait for uploads to finish before continuing',
    errName: 'Name is required',
    errEmail: 'Invalid email',
    errPhone: 'Enter an international number with country code (e.g. +9665…)',
    errCompany: 'Company name is required',
    errServices: 'Add at least one service',
    loadError: 'Could not load services, please refresh.',
    unknownDropped: 'Some unknown services from the link were ignored.',
  },
}

export type PaidCopy = typeof PAID_COPY.ar
