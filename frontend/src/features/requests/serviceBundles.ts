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
    titleAr: 'أريد إطلاق حملة جديدة',
    titleEn: 'I want to launch a new campaign',
    forAr: 'لديك منتج أو عرض جاهز وتريد بدء الإعلان عليه بشكل صحيح من أول يوم.',
    forEn: 'You have a product or offer ready and want advertising started correctly from day one.',
    services: ['new_campaign', 'campaign_objectives', 'audience_targeting', 'budget_sizing'],
    icon: 'rocket',
  },
  {
    key: 'improve',
    titleAr: 'حملاتي تعمل لكن النتائج ضعيفة',
    titleEn: 'My campaigns run but results are weak',
    forAr: 'الإنفاق مستمر والنتائج أقل من المتوقع، وتريد معرفة السبب وإصلاحه.',
    forEn: 'Spend continues but results fall short, and you want the cause found and fixed.',
    services: ['improve_performance', 'weak_results_analysis', 'reduce_cpa_cpl', 'audience_targeting'],
    icon: 'trending-up',
  },
  {
    key: 'manage',
    titleAr: 'أريد من يدير حملاتي بالكامل',
    titleEn: 'I want someone to run everything',
    forAr: 'لا وقت لديك للإدارة اليومية وتريد فريقًا يتولّى التشغيل والتقارير شهريًا.',
    forEn: 'You have no time for day-to-day management and want a team running it with monthly reporting.',
    services: ['full_monthly_management', 'multi_platform_management', 'budget_allocation', 'monthly_report'],
    icon: 'calendar-check',
  },
  {
    key: 'audit',
    titleAr: 'أريد تدقيقًا وتحليلًا قبل أي قرار',
    titleEn: 'I want an audit before deciding',
    forAr: 'تريد رأيًا مستقلًا في حسابك وحملاتك قبل زيادة الإنفاق أو تغيير الاتجاه.',
    forEn: 'You want an independent read on your account before spending more or changing direction.',
    services: ['ad_account_audit', 'campaign_performance_analysis', 'platform_comparison', 'paid_plan_review'],
    icon: 'search-check',
  },
  {
    key: 'tracking',
    titleAr: 'أرقامي غير موثوقة — التتبع لا يعمل',
    titleEn: 'My numbers are unreliable — tracking is broken',
    forAr: 'التحويلات لا تظهر أو تتضارب بين المنصة والمتجر، وتريد قياسًا يمكن الوثوق به.',
    forEn: 'Conversions are missing or disagree between platform and store, and you want measurement you can trust.',
    services: ['tracking_troubleshoot', 'conversion_api', 'event_quality_testing', 'attribution_setup'],
    icon: 'radar',
  },
  {
    key: 'strategy',
    titleAr: 'أحتاج خطة قبل أن أنفق',
    titleEn: 'I need a plan before I spend',
    forAr: 'تبدأ من الصفر أو تعيد التموضع، وتريد خطة إعلامية وميزانية ومؤشرات واضحة.',
    forEn: 'You are starting out or repositioning and want a media plan, a budget and clear KPIs.',
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
