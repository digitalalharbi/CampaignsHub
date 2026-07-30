/**
 * Goal-first entry into the paid-media catalogue.
 *
 * The catalogue holds around ninety services across ten categories. Presenting that as one flat
 * checklist asks a prospect to translate their problem into our taxonomy — so they tick one box to get
 * past the screen, and we receive a request that does not describe what they actually want.
 *
 * A bundle inverts that: the client picks the situation they are in, and we pre-select the services
 * that situation really needs. Every bundle maps to REAL service keys from the catalogue, so the
 * request that arrives is precise and the full list stays one click away for anyone who wants it.
 */

export interface ServiceBundle {
  key: string
  /** Outcome-shaped, in the client's words — never a category name. */
  titleAr: string
  titleEn: string
  /** The situation this bundle is for, so a client can recognise themselves. */
  forAr: string
  forEn: string
  /** Catalogue service keys pre-selected when the bundle is chosen. */
  services: string[]
  icon: string
}

export const SERVICE_BUNDLES: ServiceBundle[] = [
  {
    key: 'launch',
    titleAr: 'أريد بدء حملة إعلانية جديدة',
    titleEn: 'I want to start a new campaign',
    forAr: 'عندك منتج أو عرض جاهز، وتريد أن تبدأ الإعلان عليه بطريقة صحيحة من البداية.',
    forEn: 'You have a product or offer ready and want the advertising set up properly from the start.',
    services: ['new_campaign', 'campaign_objectives', 'audience_targeting', 'budget_sizing'],
    icon: 'rocket',
  },
  {
    key: 'improve',
    titleAr: 'حملاتي شغّالة لكن النتائج ضعيفة',
    titleEn: 'My campaigns run but results are weak',
    forAr: 'تصرف على الإعلانات والنتائج أقل من المتوقع، وتريد معرفة السبب وحلّه.',
    forEn: 'You are spending on ads but the results are below expectations, and you want to know why and fix it.',
    services: ['improve_performance', 'weak_results_analysis', 'reduce_cpa_cpl', 'audience_targeting'],
    icon: 'trending-up',
  },
  {
    key: 'manage',
    titleAr: 'أريد من يدير حملاتي بدلًا عني',
    titleEn: 'I want someone to run my campaigns for me',
    forAr: 'ما عندك وقت للمتابعة اليومية، وتريد فريقًا يشغّل الحملات ويرسل لك تقريرًا كل شهر.',
    forEn: 'You have no time to follow up daily and want a team to run the campaigns and send you a monthly report.',
    services: ['full_monthly_management', 'multi_platform_management', 'budget_allocation', 'monthly_report'],
    icon: 'calendar-check',
  },
  {
    key: 'audit',
    titleAr: 'أريد مراجعة حسابي قبل أي قرار',
    titleEn: 'I want my account reviewed before deciding',
    forAr: 'تريد رأيًا محايدًا في حسابك وحملاتك قبل أن تزيد الميزانية أو تغيّر الخطة.',
    forEn: 'You want a neutral opinion on your account and campaigns before raising the budget or changing the plan.',
    services: ['ad_account_audit', 'campaign_performance_analysis', 'platform_comparison', 'paid_plan_review'],
    icon: 'search-check',
  },
  {
    key: 'tracking',
    titleAr: 'أرقامي غير دقيقة والتتبع لا يعمل',
    titleEn: 'My numbers are inaccurate and tracking is broken',
    forAr: 'التحويلات ناقصة أو مختلفة بين المنصة والمتجر، وتريد أرقامًا تثق بها.',
    forEn: 'Conversions are missing or differ between the platform and the store, and you want numbers you can trust.',
    services: ['tracking_troubleshoot', 'conversion_api', 'event_quality_testing', 'attribution_setup'],
    icon: 'radar',
  },
  {
    key: 'strategy',
    titleAr: 'أحتاج خطة واضحة قبل أن أصرف',
    titleEn: 'I need a clear plan before I spend',
    forAr: 'تبدأ من الصفر أو تريد تغيير طريقتك، وتحتاج خطة وميزانية وأهدافًا محددة.',
    forEn: 'You are starting from scratch or changing approach, and need a plan, a budget and defined goals.',
    services: ['ad_strategy', 'media_plan', 'budget_sizing', 'kpi_definition'],
    icon: 'compass',
  },
]

export function findBundle(key: string): ServiceBundle | undefined {
  return SERVICE_BUNDLES.find((b) => b.key === key)
}

/**
 * Which bundle (if any) the current selection came from — so returning to the step re-highlights the
 * client's choice instead of looking like nothing was picked.
 */
export function bundleForSelection(selected: string[]): ServiceBundle | undefined {
  if (selected.length === 0) return undefined
  return SERVICE_BUNDLES.find(
    (b) => b.services.length === selected.length && b.services.every((s) => selected.includes(s)),
  )
}
