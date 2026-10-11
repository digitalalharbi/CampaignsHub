import { useQuery } from '@tanstack/react-query'
import { api, getData } from '@/lib/api/client'

/**
 * CAMPAIGN-MGMT-WRITE-001 — what the campaign's platform entities allow, and the one road to change them.
 *
 * Every action arrives with its STATE from the server: `available`, or the reason it is not — the
 * platform forbids it, CampaignsHub has not built it, the platform's app awaits credentials, the
 * account is not connected or not selected for this project, the reader lacks the permission, or
 * the row is demo data. The interface draws exactly that; it never decides availability itself.
 */
export type WriteLevel = 'campaign' | 'ad_set' | 'ad'
export type WriteActionKey = 'pause' | 'resume' | 'rename' | 'budget' | 'schedule' | 'bid_strategy' | 'archive' | 'delete' | 'duplicate'
  | 'create_ad_set' | 'create_ad' | 'targeting' | 'placements' | 'creative' | 'destination'
export type ActionState =
  | 'available' | 'provider_unsupported' | 'not_implemented' | 'awaiting_credentials'
  | 'not_connected' | 'account_not_selected' | 'no_permission' | 'demo'

export interface WriteEntity {
  id: string
  level: WriteLevel
  parent_id: string | null
  provider: string
  external_id: string
  name: string | null
  status: string | null
  daily_budget: number | null
  lifetime_budget: number | null
  currency: string | null
  bid_strategy: string | null
  account: { id: string; name: string | null; external_id: string } | null
  actions: Record<WriteActionKey, ActionState>
  budget_kinds: Array<'daily' | 'lifetime'>
  bid_strategies: string[]
  optimization_goals: string[]
  placement_families: string[]
  targeting: { countries?: string[]; age?: string; genders?: string; placements?: string[]; placement_config?: string } | null
  destination_url: string | null
}

export interface CreativeOption {
  id: string
  name: string | null
  format: string | null
  thumbnail_url: string | null
}

export interface CreateAccountOption {
  id: string
  provider: string
  name: string | null
  currency: string | null
  state: ActionState
  launch_permitted: boolean
  objectives: string[]
}

export interface WriteOptions {
  entities: WriteEntity[]
  create: CreateAccountOption[]
  /** Per platform: the project's creatives with a platform id, for binding an ad. */
  creatives?: Record<string, CreativeOption[]>
}

/** Which actions belong to which rung — anything else is not offered there at all, not «unsupported». */
export const LEVEL_ACTIONS: Record<WriteLevel, WriteActionKey[]> = {
  campaign: ['rename', 'budget', 'schedule', 'bid_strategy', 'create_ad_set', 'duplicate', 'archive', 'delete'],
  ad_set: ['rename', 'budget', 'schedule', 'bid_strategy', 'targeting', 'placements', 'create_ad', 'duplicate', 'archive', 'delete'],
  ad: ['rename', 'creative', 'destination', 'duplicate', 'archive', 'delete'],
}

export interface WriteResult {
  ok: boolean
  refusal?: string
  message?: string | null
  request_id?: string | null
  new_external_id?: string | null
  external_id?: string | null
  launched?: boolean
  launch_message?: string | null
  mirror?: Record<string, unknown>
}

const base = (projectId: string, campaignId: string) => `/projects/${projectId}/campaigns/${campaignId}`

export const writeOptionsKey = (projectId: string | null, campaignId: string | null) => ['projects', projectId, 'campaigns', campaignId, 'provider-writes'] as const

export function useWriteOptions(projectId: string | null, campaignId: string | null) {
  return useQuery({
    queryKey: writeOptionsKey(projectId, campaignId),
    queryFn: () => getData<WriteOptions>(`${base(projectId!, campaignId!)}/provider-writes`),
    enabled: Boolean(projectId && campaignId),
  })
}

/**
 * A write's answer is read whatever the HTTP status: a 409 refusal and a 422 provider error carry the
 * reason the operator needs, and treating them as exceptions would throw that reason away.
 */
async function send(url: string, body: Record<string, unknown>): Promise<WriteResult> {
  const res = await api.post<{ data?: WriteResult; message?: string; errors?: Record<string, string[]> }>(url, body, { validateStatus: () => true })
  const data = res.data?.data
  if (data) return data
  const firstError = res.data?.errors ? Object.values(res.data.errors)[0]?.[0] : undefined

  return { ok: false, refusal: 'invalid', message: firstError ?? res.data?.message ?? null }
}

export function performWrite(projectId: string, campaignId: string, body: { level: WriteLevel; entity_id: string; action: WriteActionKey } & Record<string, unknown>): Promise<WriteResult> {
  return send(`${base(projectId, campaignId)}/provider-writes`, body)
}

export function createOnPlatform(projectId: string, campaignId: string, body: { external_account_id: string; objective: string; daily_budget?: number; launch?: boolean }): Promise<WriteResult> {
  return send(`${base(projectId, campaignId)}/provider-campaigns`, body)
}

export const STATE_LABELS: Record<Exclude<ActionState, 'available'>, { ar: string; en: string }> = {
  provider_unsupported: { ar: 'لا تسمح به المنصة', en: 'The platform does not allow it' },
  not_implemented: { ar: 'غير منفَّذ بعد', en: 'Not built yet' },
  awaiting_credentials: { ar: 'بانتظار بيانات اعتماد المنصة', en: 'Awaiting platform credentials' },
  not_connected: { ar: 'الحساب غير مخوَّل — أعد ربطه', en: 'Account not authorised — reconnect it' },
  account_not_selected: { ar: 'الحساب غير محدَّد لهذا المشروع', en: 'Account not selected for this project' },
  no_permission: { ar: 'لا تملك الصلاحية', en: 'You lack the permission' },
  demo: { ar: 'بيانات تجريبية — لا توجد على أي منصة', en: 'Demo data — on no platform' },
}

export const ACTION_LABELS: Record<WriteActionKey, { ar: string; en: string }> = {
  pause: { ar: 'إيقاف', en: 'Pause' },
  resume: { ar: 'تشغيل', en: 'Resume' },
  rename: { ar: 'إعادة التسمية', en: 'Rename' },
  budget: { ar: 'الميزانية', en: 'Budget' },
  schedule: { ar: 'الجدولة', en: 'Schedule' },
  bid_strategy: { ar: 'المزايدة', en: 'Bidding' },
  duplicate: { ar: 'نسخ', en: 'Duplicate' },
  archive: { ar: 'أرشفة', en: 'Archive' },
  delete: { ar: 'حذف', en: 'Delete' },
  create_ad_set: { ar: 'مجموعة جديدة', en: 'New ad set' },
  create_ad: { ar: 'إعلان جديد', en: 'New ad' },
  targeting: { ar: 'الاستهداف', en: 'Targeting' },
  placements: { ar: 'المواضع', en: 'Placements' },
  creative: { ar: 'تغيير المحتوى', en: 'Change creative' },
  destination: { ar: 'رابط الوجهة', en: 'Landing URL' },
}

/** The provider's own consequence of a removal, said before it happens. */
export function removalConsequence(provider: string, action: 'archive' | 'delete', ar: boolean): string {
  if (action === 'archive') {
    return ar
      ? 'تُؤرشف على ميتا: يتوقف الصرف وتبقى قابلة للقراءة في التقارير. لا يمكن إعادة تشغيل عنصر مؤرشف.'
      : 'Archived on Meta: delivery stops and it stays readable in reports. An archived entity cannot be restarted.'
  }

  const name: Record<string, { ar: string; en: string }> = {
    google: { ar: 'تُزال من Google Ads نهائيًا (REMOVED) ولا يمكن استعادتها.', en: 'Removed from Google Ads for good (REMOVED); it cannot be restored.' },
    meta: { ar: 'تُحذف من ميتا ولا تعود قابلة للتشغيل.', en: 'Deleted on Meta; it can no longer run.' },
    snapchat: { ar: 'تُحذف من سناب شات نهائيًا.', en: 'Deleted from Snapchat for good.' },
    tiktok: { ar: 'تُحذف من تيك توك نهائيًا.', en: 'Deleted from TikTok for good.' },
  }

  return (name[provider] ?? { ar: 'تُحذف من المنصة نهائيًا.', en: 'Deleted from the platform for good.' })[ar ? 'ar' : 'en']
}

/** Provider-native objective names, in words. Anything unknown is shown as the platform wrote it. */
const OBJECTIVES: Record<string, { ar: string; en: string }> = {
  OUTCOME_AWARENESS: { ar: 'الوعي', en: 'Awareness' },
  OUTCOME_TRAFFIC: { ar: 'الزيارات', en: 'Traffic' },
  OUTCOME_ENGAGEMENT: { ar: 'التفاعل', en: 'Engagement' },
  OUTCOME_LEADS: { ar: 'العملاء المحتملون', en: 'Leads' },
  OUTCOME_APP_PROMOTION: { ar: 'الترويج للتطبيق', en: 'App promotion' },
  OUTCOME_SALES: { ar: 'المبيعات', en: 'Sales' },
  SEARCH: { ar: 'حملة بحث', en: 'Search campaign' },
  AWARENESS_AND_ENGAGEMENT: { ar: 'الوعي والتفاعل', en: 'Awareness & engagement' },
  TRAFFIC: { ar: 'الزيارات', en: 'Traffic' },
  LEADS: { ar: 'العملاء المحتملون', en: 'Leads' },
  APP_PROMOTION: { ar: 'الترويج للتطبيق', en: 'App promotion' },
  SALES: { ar: 'المبيعات', en: 'Sales' },
  REACH: { ar: 'الوصول', en: 'Reach' },
  VIDEO_VIEWS: { ar: 'مشاهدات الفيديو', en: 'Video views' },
  ENGAGEMENT: { ar: 'التفاعل', en: 'Engagement' },
  LEAD_GENERATION: { ar: 'العملاء المحتملون', en: 'Lead generation' },
  WEB_CONVERSIONS: { ar: 'تحويلات الموقع', en: 'Website conversions' },
  PRODUCT_SALES: { ar: 'مبيعات المنتجات', en: 'Product sales' },
}

export const objectiveName = (key: string, ar: boolean) => OBJECTIVES[key]?.[ar ? 'ar' : 'en'] ?? key

const STRATEGIES: Record<string, { ar: string; en: string }> = {
  LOWEST_COST_WITHOUT_CAP: { ar: 'أقل تكلفة بلا سقف', en: 'Lowest cost, no cap' },
  LOWEST_COST_WITH_BID_CAP: { ar: 'سقف للمزايدة', en: 'Bid cap' },
  COST_CAP: { ar: 'سقف للتكلفة', en: 'Cost cap' },
  MANUAL_CPC: { ar: 'تكلفة نقرة يدوية', en: 'Manual CPC' },
  MAXIMIZE_CONVERSIONS: { ar: 'أقصى تحويلات', en: 'Maximise conversions' },
  MAXIMIZE_CONVERSION_VALUE: { ar: 'أقصى قيمة تحويل', en: 'Maximise conversion value' },
  TARGET_SPEND: { ar: 'أقصى نقرات', en: 'Maximise clicks' },
  AUTO_BID: { ar: 'مزايدة تلقائية', en: 'Auto bid' },
  LOWEST_COST_WITH_MAX_BID: { ar: 'سقف أعلى للمزايدة', en: 'Max bid' },
  TARGET_COST: { ar: 'تكلفة مستهدفة', en: 'Target cost' },
  BID_TYPE_NO_BID: { ar: 'بلا مزايدة (تلقائي)', en: 'No bid (automatic)' },
  BID_TYPE_CUSTOM: { ar: 'مزايدة محددة', en: 'Custom bid' },
}

export const strategyName = (key: string, ar: boolean) => STRATEGIES[key]?.[ar ? 'ar' : 'en'] ?? key

/** Strategies that are a cap and therefore need an amount. */
export const NEEDS_AMOUNT = new Set(['LOWEST_COST_WITH_BID_CAP', 'COST_CAP', 'LOWEST_COST_WITH_MAX_BID', 'TARGET_COST', 'BID_TYPE_CUSTOM'])

const GOALS: Record<string, { ar: string; en: string }> = {
  LINK_CLICKS: { ar: 'نقرات الرابط', en: 'Link clicks' },
  LANDING_PAGE_VIEWS: { ar: 'زيارات صفحة الهبوط', en: 'Landing page views' },
  OFFSITE_CONVERSIONS: { ar: 'التحويلات', en: 'Conversions' },
  LEAD_GENERATION: { ar: 'العملاء المحتملون', en: 'Leads' },
  REACH: { ar: 'الوصول', en: 'Reach' },
  IMPRESSIONS: { ar: 'مرات الظهور', en: 'Impressions' },
  THRUPLAY: { ar: 'مشاهدات كاملة', en: 'ThruPlays' },
  SWIPES: { ar: 'السحب للأعلى', en: 'Swipe-ups' },
  VIDEO_VIEWS: { ar: 'مشاهدات الفيديو', en: 'Video views' },
  PIXEL_PURCHASE: { ar: 'شراء (البكسل)', en: 'Purchases (pixel)' },
  PIXEL_SIGNUP: { ar: 'تسجيل (البكسل)', en: 'Sign-ups (pixel)' },
}

export const goalName = (key: string, ar: boolean) => GOALS[key]?.[ar ? 'ar' : 'en'] ?? key

const FAMILIES: Record<string, { ar: string; en: string }> = {
  facebook: { ar: 'فيسبوك', en: 'Facebook' },
  instagram: { ar: 'إنستغرام', en: 'Instagram' },
  audience_network: { ar: 'شبكة الجمهور', en: 'Audience Network' },
  messenger: { ar: 'ماسنجر', en: 'Messenger' },
}

export const familyName = (key: string, ar: boolean) => FAMILIES[key]?.[ar ? 'ar' : 'en'] ?? key

/** «SA, ae ,KW» → ['SA','AE','KW'] — two-letter codes only; anything else is dropped and the form says so. */
export function parseCountries(text: string): string[] {
  return Array.from(new Set(text.split(/[\s,،]+/).map((c) => c.trim().toUpperCase()).filter((c) => /^[A-Z]{2}$/.test(c))))
}
