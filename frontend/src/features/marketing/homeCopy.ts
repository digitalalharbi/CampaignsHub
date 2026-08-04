import { features } from '@/lib/features'

/**
 * Public homepage copy (ar + en) — v5 CUSTOMER-facing rebuild.
 *
 * Kept as data so the marketing page stays declarative. All wording here is written for an EXTERNAL
 * visitor (an advertiser or a service client). Internal/system vocabulary is deliberately absent —
 * no subscription-model, multi-tenant, back-office or entitlement jargon (see the forbidden-terms
 * guard in the test). The homepage never exposes an internal-admin login.
 *
 * Structure (lean, balanced): one hero (value + demo preview + a "how do you want to start?" options
 * card with an inline services selector), then كيف يعمل · الخدمات · أهم المميزات · المنصات المدعومة ·
 * التقارير والتنبيهات · final CTA · Footer. The 4 start-options live ONLY in the hero card.
 */
export type Locale = 'ar' | 'en'

export interface HomeCopy {
  dir: 'rtl' | 'ltr'
  nav: {
    features: string; how: string; services: string; integrations: string
    login: string; start: string; request: string; clientLogin: string; dashboard: string
  }
  hero: { eyebrow: string; title: string; desc: string; support: string; points: string[]; demoTag: string; currency: string }
  /** Per-portal public experiences (HOME-013): distinct copy + preview per ?portal (paid default). */
  portals: Record<
    'influencer' | 'client',
    { eyebrow: string; title: string; desc: string; support: string; points: string[]; previewTitle: string; previewItems: string[] }
  >
  /** Localised labels for the dark CampaignsHub demo preview (numbers/platform names live in the component). */
  preview: {
    kpis: { spend: string; results: string; active: string; cpr: string }
    tabs: { comparison: string; distribution: string; creatives: string; campaigns: string }
    cols: { platform: string; spend: string; results: string; active: string; cpr: string; roas: string; sync: string }
    roasNote: string
    syncPrefix: string
    syncUnit: string
    creatives: { name: string; metric: string }[]
    campaigns: { name: string; metric: string }[]
  }
  /**
   * The dashboard shown inside the hero. It is the product's own overview in miniature: the headline
   * KPIs, the best campaigns, the platform comparison, where the money went, the budgets and the
   * scheduled reports — the same things the product shows once real accounts are connected.
   *
   * The numbers are deliberately consistent with each other (campaign spend sums to total spend,
   * results sum to total results, average cost per result is total spend ÷ total results), because a
   * demo whose arithmetic does not add up teaches a visitor to distrust the real thing.
   */
  dashboard: {
    dateRange: string
    /** Which objective these figures cover — comparing across objectives would be misleading. */
    objectiveLabel: string
    demoBadge: string
    footnote: string
    vsPrevious: string
    kpis: { label: string; value: string; delta: string; up: boolean; good: boolean }[]
    panels: { campaigns: string; comparison: string; distribution: string; budgets: string; reports: string }
    cols: { campaign: string; platform: string; spend: string; results: string; cpr: string; roas: string; share: string }
    campaigns: { name: string; platform: string; spend: string; results: string; cpr: string }[]
    platforms: { name: string; spend: string; results: string; roas: number; share: number }[]
    budgets: { name: string; budget: string; used: string; pct: number }[]
    reports: { name: string; desc: string; when: string }[]
  }

  /**
   * The hero's interactive start panel: pick what describes you, and the panel answers with what that
   * path includes and a single call to action that goes straight there.
   */
  start: {
    eyebrow: string
    title: string
    subtitle: string
    question: string
    demoNote: string
    activeLabel: string
    consumedLabel: string
    trust: string[]
    paths: {
      key: string
      title: string
      kicker: string
      desc: string
      includes: string[]
      cta: string
      to?: string
      action?: 'reveal-services'
    }[]
  }

  /** The numbered journey strip under the hero — the path from connecting accounts to a report. */
  journey: { label: string; cta: string; steps: string[] }

  /** The hero "كيف تريد البدء؟" options card. Options either navigate (`to`) or reveal the inline selector. */
  options: {
    title: string
    subtitle: string
    cards: { title: string; desc: string; to?: string; action?: 'reveal-services' }[]
    login: { helper: string; actions: { label: string; to: string }[] }
  }
  /** Strings for the inline paid-media services selector revealed inside the options card (option 3). */
  services: {
    hint: string; popular: string; selected: string; clearAll: string; continueCta: string
    viewAll: string; drawerTitle: string; drawerSubtitle: string; search: string; allCategories: string
    custom: string; customDesc: string; empty: string; close: string; errorTitle: string; errorDesc: string; retry: string
  }
  steps: { title: string; subtitle: string; items: { title: string; desc: string }[] }
  serviceAreas: { title: string; subtitle: string; cta: string; items: { title: string; desc: string }[] }
  features: { title: string; subtitle: string; items: { title: string; desc: string }[] }
  platforms: {
    title: string; subtitle: string; note: string
    items: { label: string; desc: string; status: string; tone: 'ok' | 'dev' | 'await' | 'soon' }[]
  }
  reports: { title: string; subtitle: string; formatsLabel: string; formats: string[]; alertsLabel: string; alerts: string[] }
  finalCta: { title: string; subtitle: string; start: string; request: string }
  footer: {
    tagline: string
    contactLabel: string
    email: string
    /** Grouped footer navigation — product, company and legal each get their own column. */
    groups: { title: string; links: { label: string; to: string }[] }[]
    rights: string
    /** Kept for compatibility with the existing product column. */
    product: string
    links: { label: string; to: string }[]
    legal: string[]
  }
}

const ar: HomeCopy = {
  dir: 'rtl',
  nav: {
    features: 'المميزات', how: 'كيف يعمل', services: 'الخدمات', integrations: 'التكاملات والتقارير',
    login: 'تسجيل الدخول', start: 'إنشاء حساب', request: 'اطلب خدمة', clientLogin: 'متابعة طلباتي', dashboard: 'لوحة التحكم',
  },
  hero: {
    eyebrow: 'إدارة الحملات الإعلانية المدفوعة',
    title: 'كل حملاتك الإعلانية المدفوعة في مكان واحد',
    desc: 'تابع حملاتك وميزانياتك ونتائجك عبر المنصات، قارن الأداء، واكتشف فرص التحسين من لوحة واحدة واضحة.',
    support: 'أدر حملاتك بنفسك، أو اختر الخدمة التي تحتاجها ودعنا نساعدك في تنفيذها.',
    points: ['متابعة موحدة لجميع المنصات', 'مقارنة واضحة بين الحملات', 'بيانات من الحسابات المرتبطة', 'تقارير وتنبيهات تساعدك على اتخاذ القرار'],
    demoTag: 'معاينة توضيحية ببيانات تجريبية',
    currency: 'ر.س',
  },
  portals: {
    influencer: {
      eyebrow: 'حملات المؤثرين والمحتوى',
      title: 'أدر حملات المؤثرين والمحتوى من مكان واحد',
      desc: 'أرسل تفاصيل حملتك، وتابع المحتويات والمراجعات والموافقات والتسليمات، وقِس النتائج بوضوح.',
      support: 'من الطلب حتى التسليم — كل خطوات حملة المؤثرين في مكان واحد.',
      points: ['إدارة طلبات المؤثرين', 'مراجعة المحتويات والموافقات', 'متابعة التسليمات', 'قياس نتائج الحملة'],
      previewTitle: 'لمحة عن حملة مؤثرين',
      previewItems: ['طلبات المؤثرين', 'المحتويات قيد المراجعة', 'الموافقات', 'التسليمات', 'نتائج الحملة'],
    },
    client: {
      eyebrow: 'متابعة طلباتك',
      title: 'تابع طلباتك وعروضك وفواتيرك من مكان واحد',
      desc: 'تابع حالة طلباتك، وعروض الأسعار، والفواتير والمدفوعات، والرسائل والملفات وتنفيذ الخدمة.',
      support: 'كل ما يخص طلبك — العرض، الفاتورة، الرسائل والتنفيذ — في بوابة واحدة.',
      points: ['متابعة حالة الطلبات', 'عروض الأسعار والفواتير', 'المدفوعات والرسائل', 'الملفات وتقارير التنفيذ'],
      previewTitle: 'لمحة عن متابعة الطلبات',
      previewItems: ['الطلبات', 'عروض الأسعار', 'الفواتير', 'المدفوعات', 'الرسائل والملفات'],
    },
  },
  preview: {
    kpis: { spend: 'إجمالي الإنفاق', results: 'النتائج', active: 'الحملات النشطة', cpr: 'متوسط تكلفة النتيجة' },
    tabs: { comparison: 'مقارنة أداء المنصات', distribution: 'توزيع الإنفاق', creatives: 'أفضل المحتويات الإعلانية', campaigns: 'أعلى الحملات أداءً' },
    cols: { platform: 'المنصة', spend: 'الإنفاق', results: 'النتائج', active: 'نشطة', cpr: 'التكلفة', roas: 'العائد', sync: 'آخر مزامنة' },
    roasNote: '* العائد يظهر عند ملاءمة هدف الحملة.',
    syncPrefix: 'قبل',
    syncUnit: 'د',
    creatives: [
      { name: 'فيديو UGC — تخفيضات الصيف', metric: 'نقر 3.2% · نتائج 214' },
      { name: 'كاروسيل — المجموعة الجديدة', metric: 'نقر 2.6% · نتائج 168' },
      { name: 'فيديو قصير — عرض محدود', metric: 'نقر 2.1% · نتائج 129' },
    ],
    campaigns: [
      { name: 'حملة المبيعات — الرياض', metric: 'إنفاق 14,200 ر.س · عائد 3.8' },
      { name: 'تحويلات المتجر — الخليج', metric: 'إنفاق 11,600 ر.س · عائد 3.2' },
      { name: 'إعادة استهداف — السلة المتروكة', metric: 'إنفاق 6,400 ر.س · عائد 4.1' },
    ],
  },
  dashboard: {
    dateRange: '1 أبريل — 25 مايو 2026',
    objectiveLabel: 'هدف: المبيعات — تُقارن الحملات ضمن الهدف نفسه فقط',
    demoBadge: 'معاينة توضيحية ببيانات تجريبية',
    footnote: 'جميع الأرقام تقريبية وتُستخدم لأغراض توضيحية فقط.',
    vsPrevious: 'مقارنة بالفترة السابقة',
    kpis: [
      { label: 'إجمالي الإنفاق', value: '48,900 SAR', delta: '18%', up: true, good: true },
      { label: 'النتائج المحققة', value: '1,556', delta: '22%', up: true, good: true },
      { label: 'الحملات النشطة', value: '16', delta: '7%', up: true, good: true },
      { label: 'متوسط تكلفة النتيجة', value: '31.4 SAR', delta: '12%', up: false, good: true },
    ],
    panels: {
      campaigns: 'أفضل الحملات',
      comparison: 'مقارنة أداء المنصات',
      distribution: 'توزيع الإنفاق حسب المنصة',
      budgets: 'الميزانيات',
      reports: 'التقارير والتنبيهات',
    },
    cols: { campaign: 'الحملة', platform: 'المنصة', spend: 'الإنفاق', results: 'النتائج', cpr: 'التكلفة', roas: 'العائد', share: 'الحصة' },
    campaigns: [
      { name: 'إطلاق المجموعة الصيفية', platform: 'Snapchat', spend: '12,400', results: '486', cpr: '25.5' },
      { name: 'عروض نهاية الأسبوع', platform: 'Meta', spend: '10,800', results: '372', cpr: '29.0' },
      { name: 'حملة المحتوى القصير', platform: 'TikTok', spend: '8,600', results: '271', cpr: '31.7' },
      { name: 'ترويج تطبيق الجوال', platform: 'Google Ads', spend: '7,900', results: '214', cpr: '36.9' },
      { name: 'التجديد السنوي', platform: 'X', spend: '5,200', results: '132', cpr: '39.4' },
      { name: 'عملاء قطاع الأعمال', platform: 'LinkedIn', spend: '4,000', results: '81', cpr: '49.4' },
    ],
    platforms: [
      { name: 'Snapchat', spend: '12,400', results: '486', roas: 3.6, share: 25 },
      { name: 'Meta', spend: '10,800', results: '372', roas: 3.1, share: 22 },
      { name: 'TikTok', spend: '8,600', results: '271', roas: 2.8, share: 18 },
      { name: 'Google Ads', spend: '7,900', results: '214', roas: 2.4, share: 16 },
      { name: 'X', spend: '5,200', results: '132', roas: 1.9, share: 11 },
      { name: 'LinkedIn', spend: '4,000', results: '81', roas: 1.6, share: 8 },
    ],
    budgets: [
      { name: 'ميزانية الربع — المبيعات', budget: '120,000', used: '86,400', pct: 72 },
      { name: 'الوعي — إطلاق المجموعة', budget: '45,000', used: '19,800', pct: 44 },
      { name: 'إعادة الاستهداف', budget: '30,000', used: '27,900', pct: 93 },
    ],
    reports: [
      { name: 'تقرير الأداء الأسبوعي', desc: 'PDF · يُرسل للعميل', when: 'الأحد 08:00' },
      { name: 'ملخص الميزانيات الشهري', desc: 'Excel · للفريق', when: 'أول الشهر' },
      { name: 'تنبيه تجاوز الميزانية', desc: 'عند تجاوز 90% من المخطط', when: 'فوري' },
    ],
  },
  start: {
    eyebrow: 'داخل النظام',
    title: 'ابدأ مع CampaignsHub',
    subtitle: 'اختر ما يصفك، وسنفتح لك المسار المناسب مباشرة.',
    question: 'ما الذي تريد فعله؟',
    demoNote: 'معاينة توضيحية للواجهة ببيانات تجريبية — لا تمثل نتائج عميل حقيقي.',
    activeLabel: 'حملة نشطة',
    consumedLabel: 'المصروف من الميزانية',
    trust: ['تسجيل آمن', 'تحقّق البريد والجوال', 'مساحة مستقلة لكل حساب', 'تنبيهات ومتابعة آلية'],
    paths: [
      {
        key: 'self-service',
        title: 'أدير حملاتي بنفسي',
        kicker: 'معلن',
        desc: 'حساب واحد يجمع حملاتك ومنصاتك وميزانياتك وتقاريرك في مكان واحد.',
        includes: ['ربط المنصات', 'متابعة الحملات', 'الميزانيات والتنبيهات', 'التقارير والتصدير'],
        cta: 'تابع كمعلن',
        to: '/register?journey=self-service&module=paid-media',
      },
      {
        key: 'multi-client',
        title: 'أدير حملات لعدة عملاء',
        kicker: 'وكالة',
        desc: 'نظّم عملاءك ومشاريعك وحملاتك، وتابع أداء كل عميل بشكل مستقل.',
        includes: ['عملاء ومشاريع', 'صلاحيات الفريق', 'تقارير لكل عميل', 'مقارنة المنصات'],
        cta: 'تابع كوكالة',
        to: '/register?journey=multi-client&module=paid-media',
      },
      {
        key: 'services',
        title: 'أحتاج خدمات إعلانية',
        kicker: 'طلب خدمة',
        desc: 'اختر الخدمة التي تحتاجها وسنتولى التنفيذ، مع متابعة واضحة لكل خطوة.',
        includes: ['إدارة وتحسين الحملات', 'التتبع والتحليل', 'عرض سعر وفاتورة', 'تقارير التنفيذ'],
        cta: 'اختر الخدمات',
        action: 'reveal-services',
      },
      {
        key: 'influencer',
        title: 'أحتاج مؤثرين أو محتوى UGC',
        kicker: 'مؤثرون ومحتوى',
        desc: 'أرسل تفاصيل حملتك وتابع المحتوى والموافقات والتسليمات من حسابك.',
        includes: ['طلب الحملة', 'مراجعة المحتوى', 'الموافقات والتسليمات', 'قياس النتائج'],
        cta: 'ابدأ طلب الحملة',
        to: '/requests/new?module=influencer-marketing',
      },
    ],
  },
  journey: {
    label: 'رحلة العمل',
    cta: 'استعرض المميزات',
    steps: ['اربط المنصات', 'وحّد الحملات', 'تابع الميزانيات', 'قارن الأداء', 'تقارير وتنبيهات'],
  },
  options: {
    title: 'كيف تريد البدء؟',
    subtitle: 'اختر ما يناسب احتياجك وسنأخذك مباشرة إلى الخطوة التالية.',
    cards: [
      { title: 'أدير حملاتي بنفسي', desc: 'اجمع حملاتك ومنصاتك وميزانياتك وتقاريرك في مكان واحد.', to: '/register?journey=self-service&module=paid-media' },
      { title: 'أدير حملات لعدة عملاء', desc: 'نظّم عملاءك ومشاريعك وحملاتك وتابع أداء كل عميل بشكل مستقل.', to: '/register?journey=multi-client&module=paid-media' },
      { title: 'أحتاج خدمات إعلانية', desc: 'اختر خدمات الإدارة أو التحسين أو التتبع أو التحليل أو التقارير.', action: 'reveal-services' },
      { title: 'أحتاج مؤثرين أو محتوى UGC', desc: 'أرسل تفاصيل حملتك وتابع المحتوى والتنفيذ والتسليمات من حسابك.', to: '/requests/new?module=influencer-marketing' },
    ],
    login: {
      helper: 'سجّل الدخول لإدارة حملاتك، أو تابع طلباتك وعروض الأسعار والفواتير والتنفيذ.',
      actions: [
        { label: 'تسجيل الدخول', to: '/login' },
        { label: 'متابعة طلباتي', to: '/login' },
      ],
    },
  },
  services: {
    hint: 'اختر خدمة أو أكثر ثم أكمل طلبك.',
    popular: 'الأكثر طلبًا',
    selected: 'الخدمات المختارة',
    clearAll: 'مسح الكل',
    continueCta: 'أكمل طلبك',
    viewAll: 'عرض جميع الخدمات',
    drawerTitle: 'جميع الخدمات الإعلانية',
    drawerSubtitle: 'ابحث أو صفِّ حسب الفئة، واختر ما يناسب طلبك.',
    search: 'ابحث عن خدمة…',
    allCategories: 'كل الفئات',
    custom: 'طلب مخصص',
    customDesc: 'لم تجد ما تبحث عنه؟ أرسل طلبًا مخصصًا وسنتواصل معك.',
    empty: 'لا توجد خدمات متاحة حاليًا.',
    close: 'تم',
    errorTitle: 'تعذّر تحميل الخدمات',
    errorDesc: 'حدث خطأ أثناء جلب قائمة الخدمات. أعد المحاولة.',
    retry: 'إعادة المحاولة',
  },
  steps: {
    title: 'كيف يعمل',
    subtitle: 'من الربط إلى التقرير في أربع خطوات.',
    items: [
      { title: 'اربط حساباتك', desc: 'ربط آمن لحسابات الإعلانات عبر المنصات.' },
      { title: 'نظّم حملاتك', desc: 'عملاء ومشاريع وحملات، كل مشروع مستقل.' },
      { title: 'تابع الأداء', desc: 'ميزانيات ونتائج ومؤشرات حسب هدف الحملة.' },
      { title: 'استلم التقارير', desc: 'تقارير واضحة وتنبيهات تلقائية للقرار.' },
    ],
  },
  serviceAreas: {
    title: 'الخدمات',
    subtitle: 'إن لم ترغب في الإدارة بنفسك، اطلب الخدمة التي تحتاجها ونساعدك في تنفيذها.',
    cta: 'اطلب خدمة',
    items: [
      { title: 'إدارة الحملات', desc: 'إطلاق وإدارة حملاتك المدفوعة عبر المنصات.' },
      { title: 'تحسين الأداء', desc: 'خفض تكلفة النتيجة ورفع العائد من حملاتك.' },
      { title: 'التتبع والقياس', desc: 'إعداد البكسل والأحداث والتحويلات لقياس دقيق.' },
      { title: 'التحليل والتدقيق', desc: 'تدقيق حساباتك وتحليل الأداء واكتشاف الفرص.' },
      { title: 'التقارير', desc: 'تقارير واضحة حسب هدف الحملة، جاهزة للمشاركة.' },
      { title: 'الاستشارات', desc: 'جلسات ومراجعات لخطة الإعلان واختيار المنصات.' },
    ],
  },
  features: {
    title: 'أهم المميزات',
    subtitle: 'أدوات واضحة مبنية على بيانات حساباتك الفعلية.',
    items: [
      { title: 'متابعة موحدة', desc: 'حملاتك ومنصاتك ونتائجك في لوحة واحدة.' },
      { title: 'مقارنة الأداء', desc: 'قارن الحملات والمنصات واكتشف الأفضل بسرعة.' },
      { title: 'التقارير', desc: 'تقارير حسب الهدف، جاهزة للعميل أو للفريق.' },
      { title: 'الميزانيات', desc: 'راقب سرعة الصرف وتوقّع التجاوز مبكرًا.' },
      { title: 'المحتويات الإعلانية', desc: 'قارن أداء الإعلانات واعرف الأفضل تأثيرًا.' },
      { title: 'التنبيهات', desc: 'تنبيه عند ارتفاع التكلفة أو توقف المزامنة.' },
    ],
  },
  platforms: {
    title: 'المنصات المدعومة',
    subtitle: 'اجمع بيانات حملاتك من المنصات الرئيسية في مكان واحد.',
    note: 'المنصات الست مدعومة بالكامل — تربط حسابك الإعلاني بنفسك، ولا تظهر أي أرقام قبل أول مزامنة فعلية.',
    items: [
      { label: 'Snapchat Ads', desc: 'حملات الوعي والتحويلات والكتالوج', status: 'متاحة للربط', tone: 'ok' },
      { label: 'TikTok Ads', desc: 'حملات الفيديو والمحتوى القصير', status: 'متاحة للربط', tone: 'ok' },
      { label: 'Meta (Facebook · Instagram)', desc: 'الحملات والمجموعات والإعلانات والنتائج اليومية', status: 'متاحة للربط', tone: 'ok' },
      { label: 'Google Ads', desc: 'حملات البحث والتسوق والأداء الأقصى', status: 'متاحة للربط', tone: 'ok' },
      { label: 'X (Twitter) Ads', desc: 'حملات التفاعل والوصول', status: 'متاحة للربط', tone: 'ok' },
      { label: 'LinkedIn Ads', desc: 'حملات قطاع الأعمال والعملاء المحتملين', status: 'متاحة للربط', tone: 'ok' },
    ],
  },
  reports: {
    title: 'التقارير والتنبيهات',
    subtitle: 'تقارير جاهزة للمشاركة، وتنبيهات تصلك في الوقت المناسب.',
    formatsLabel: 'صيغ ومخرجات التقارير',
    formats: ['PDF', 'XLSX', 'CSV', 'أسبوعية', 'شهرية', 'تنفيذية', 'روابط آمنة', 'إرسال مجدول'],
    alertsLabel: 'تنبيهات ذكية',
    alerts: ['ارتفاع تكلفة النتيجة', 'اقتراب تجاوز الميزانية', 'توقف المزامنة', 'توقف حملة نشطة'],
  },
  finalCta: {
    title: 'تابع وقُم بإدارة حملاتك من مكان واحد',
    subtitle: 'أنشئ حسابك خلال دقائق، أو أرسل طلب خدمة وتابعه عبر رابط آمن.',
    start: 'إنشاء حساب',
    request: 'اطلب خدمة',
  },
  footer: {
    tagline: 'إدارة الحملات الإعلانية المدفوعة — العملاء والمشاريع والحملات والتحليلات والتقارير في مكان واحد.',
    product: 'المنتج',
    links: [
      { label: 'إنشاء حساب', to: '/register' },
      { label: 'تسجيل الدخول', to: '/login' },
      { label: 'اطلب خدمة', to: '/requests/new' },
      { label: 'متابعة طلباتي', to: '/login' },
    ],
    legal: ['الخصوصية', 'الشروط', 'الدعم'],
    contactLabel: 'للتواصل',
    email: 'info@CampaignsHub.io',
    groups: [
      {
        title: 'المنتج',
        links: [
          { label: 'إنشاء حساب', to: '/register' },
          { label: 'تسجيل الدخول', to: '/login' },
          { label: 'اطلب خدمة', to: '/requests/new' },
          { label: 'متابعة طلباتي', to: '/login' },
        ],
      },
      {
        title: 'الشركة',
        links: [
          { label: 'من نحن', to: '/about' },
          { label: 'تواصل معنا', to: '/contact' },
          { label: 'الدعم والمساعدة', to: '/support' },
          { label: 'الأسئلة الشائعة', to: '/faq' },
        ],
      },
      {
        title: 'السياسات',
        links: [
          { label: 'سياسة الخصوصية', to: '/privacy' },
          { label: 'الشروط والأحكام', to: '/terms' },
          { label: 'معالجة البيانات', to: '/data-processing' },
          { label: 'ملفات تعريف الارتباط', to: '/cookies' },
          { label: 'الأمان', to: '/security' },
        ],
      },
    ],
    rights: 'جميع الحقوق محفوظة',
  },
}

const en: HomeCopy = {
  dir: 'ltr',
  nav: {
    features: 'Features', how: 'How it works', services: 'Services', integrations: 'Integrations & reports',
    login: 'Log in', start: 'Create account', request: 'Request a service', clientLogin: 'Track my requests', dashboard: 'Dashboard',
  },
  hero: {
    eyebrow: 'Paid advertising management',
    title: 'All your paid ad campaigns in one place',
    desc: 'Track your campaigns, budgets and results across platforms, compare performance, and spot optimization opportunities from one clear dashboard.',
    support: 'Run your campaigns yourself, or pick the service you need and let us help you deliver it.',
    points: ['Unified tracking across all platforms', 'Clear comparison between campaigns', 'Data from your connected accounts', 'Reports and alerts that help you decide'],
    demoTag: 'Illustrative preview with demo data',
    currency: 'SAR',
  },
  portals: {
    influencer: {
      eyebrow: 'Influencer & content campaigns',
      title: 'Run influencer & content campaigns from one place',
      desc: 'Send your brief, follow content, reviews, approvals and deliverables, and measure results clearly.',
      support: 'From request to delivery — every step of an influencer campaign in one place.',
      points: ['Manage influencer requests', 'Review content & approvals', 'Track deliverables', 'Measure campaign results'],
      previewTitle: 'Influencer campaign at a glance',
      previewItems: ['Influencer requests', 'Content in review', 'Approvals', 'Deliverables', 'Campaign results'],
    },
    client: {
      eyebrow: 'Track your requests',
      title: 'Track your requests, quotes and invoices in one place',
      desc: 'Follow your request status, quotes, invoices and payments, plus messages, files and delivery.',
      support: 'Everything about your request — quote, invoice, messages and delivery — in one portal.',
      points: ['Follow request status', 'Quotes & invoices', 'Payments & messages', 'Files & delivery reports'],
      previewTitle: 'Request tracking at a glance',
      previewItems: ['Requests', 'Quotes', 'Invoices', 'Payments', 'Messages & files'],
    },
  },
  preview: {
    kpis: { spend: 'Total spend', results: 'Results', active: 'Active campaigns', cpr: 'Avg cost per result' },
    tabs: { comparison: 'Platform performance', distribution: 'Spend distribution', creatives: 'Top creatives', campaigns: 'Top campaigns' },
    cols: { platform: 'Platform', spend: 'Spend', results: 'Results', active: 'Active', cpr: 'Cost', roas: 'Return', sync: 'Last sync' },
    roasNote: '* Return is shown when it fits the campaign objective.',
    syncPrefix: '',
    syncUnit: 'm ago',
    creatives: [
      { name: 'UGC video — Summer sale', metric: 'CTR 3.2% · 214 results' },
      { name: 'Carousel — New collection', metric: 'CTR 2.6% · 168 results' },
      { name: 'Short video — Limited offer', metric: 'CTR 2.1% · 129 results' },
    ],
    campaigns: [
      { name: 'Sales campaign — Riyadh', metric: 'Spend 14,200 SAR · ROAS 3.8' },
      { name: 'Store conversions — Gulf', metric: 'Spend 11,600 SAR · ROAS 3.2' },
      { name: 'Retargeting — Abandoned cart', metric: 'Spend 6,400 SAR · ROAS 4.1' },
    ],
  },
  dashboard: {
    dateRange: '1 April — 25 May 2026',
    objectiveLabel: 'Objective: Sales — campaigns are compared within one objective only',
    demoBadge: 'Illustrative preview with demo data',
    footnote: 'All figures are approximate and shown for illustration only.',
    vsPrevious: 'vs previous period',
    kpis: [
      { label: 'Total spend', value: '48,900 SAR', delta: '18%', up: true, good: true },
      { label: 'Results', value: '1,556', delta: '22%', up: true, good: true },
      { label: 'Active campaigns', value: '16', delta: '7%', up: true, good: true },
      { label: 'Avg. cost per result', value: '31.4 SAR', delta: '12%', up: false, good: true },
    ],
    panels: {
      campaigns: 'Top campaigns',
      comparison: 'Platform performance',
      distribution: 'Spend by platform',
      budgets: 'Budgets',
      reports: 'Reports & alerts',
    },
    cols: { campaign: 'Campaign', platform: 'Platform', spend: 'Spend', results: 'Results', cpr: 'Cost', roas: 'ROAS', share: 'Share' },
    campaigns: [
      { name: 'Summer collection launch', platform: 'Snapchat', spend: '12,400', results: '486', cpr: '25.5' },
      { name: 'Weekend offers', platform: 'Meta', spend: '10,800', results: '372', cpr: '29.0' },
      { name: 'Short-form content', platform: 'TikTok', spend: '8,600', results: '271', cpr: '31.7' },
      { name: 'Mobile app promotion', platform: 'Google Ads', spend: '7,900', results: '214', cpr: '36.9' },
      { name: 'Annual renewal', platform: 'X', spend: '5,200', results: '132', cpr: '39.4' },
      { name: 'B2B leads', platform: 'LinkedIn', spend: '4,000', results: '81', cpr: '49.4' },
    ],
    platforms: [
      { name: 'Snapchat', spend: '12,400', results: '486', roas: 3.6, share: 25 },
      { name: 'Meta', spend: '10,800', results: '372', roas: 3.1, share: 22 },
      { name: 'TikTok', spend: '8,600', results: '271', roas: 2.8, share: 18 },
      { name: 'Google Ads', spend: '7,900', results: '214', roas: 2.4, share: 16 },
      { name: 'X', spend: '5,200', results: '132', roas: 1.9, share: 11 },
      { name: 'LinkedIn', spend: '4,000', results: '81', roas: 1.6, share: 8 },
    ],
    budgets: [
      { name: 'Quarterly — Sales', budget: '120,000', used: '86,400', pct: 72 },
      { name: 'Awareness — launch', budget: '45,000', used: '19,800', pct: 44 },
      { name: 'Retargeting', budget: '30,000', used: '27,900', pct: 93 },
    ],
    reports: [
      { name: 'Weekly performance report', desc: 'PDF · sent to the client', when: 'Sunday 08:00' },
      { name: 'Monthly budget summary', desc: 'Excel · internal team', when: 'Start of month' },
      { name: 'Budget overspend alert', desc: 'When 90% of plan is passed', when: 'Immediate' },
    ],
  },
  start: {
    eyebrow: 'Inside the product',
    title: 'Start with CampaignsHub',
    subtitle: 'Pick what describes you and we will open the right path.',
    question: 'What would you like to do?',
    demoNote: 'Illustrative interface preview with demo data — not a real client’s results.',
    activeLabel: 'Active campaign',
    consumedLabel: 'Budget consumed',
    trust: ['Secure sign-up', 'Email & phone verification', 'A separate space per account', 'Automatic alerts and tracking'],
    paths: [
      {
        key: 'self-service',
        title: 'I run my own campaigns',
        kicker: 'Advertiser',
        desc: 'One account that brings your campaigns, platforms, budgets and reports together.',
        includes: ['Connect platforms', 'Track campaigns', 'Budgets and alerts', 'Reports and export'],
        cta: 'Continue as an advertiser',
        to: '/register?journey=self-service&module=paid-media',
      },
      {
        key: 'multi-client',
        title: 'I manage campaigns for several clients',
        kicker: 'Agency',
        desc: 'Organize clients, projects and campaigns, and track each client independently.',
        includes: ['Clients and projects', 'Team permissions', 'Per-client reports', 'Platform comparison'],
        cta: 'Continue as an agency',
        to: '/register?journey=multi-client&module=paid-media',
      },
      {
        key: 'services',
        title: 'I need paid-media services',
        kicker: 'Service request',
        desc: 'Choose the service you need and we will run it, with clear tracking at every step.',
        includes: ['Campaign management', 'Tracking and analysis', 'Quote and invoice', 'Delivery reports'],
        cta: 'Choose services',
        action: 'reveal-services',
      },
      {
        key: 'influencer',
        title: 'I need influencers or UGC content',
        kicker: 'Influencers & content',
        desc: 'Send your campaign details and follow content, approvals and deliveries from your account.',
        includes: ['Campaign request', 'Content review', 'Approvals and delivery', 'Result measurement'],
        cta: 'Start the request',
        to: '/requests/new?module=influencer-marketing',
      },
    ],
  },
  journey: {
    label: 'How it flows',
    cta: 'Explore the features',
    steps: ['Connect platforms', 'Unify campaigns', 'Watch budgets', 'Compare performance', 'Reports and alerts'],
  },
  options: {
    title: 'How do you want to start?',
    subtitle: 'Pick what fits your need and we’ll take you straight to the next step.',
    cards: [
      { title: 'I run my own campaigns', desc: 'Bring your campaigns, platforms, budgets and reports into one place.', to: '/register?journey=self-service&module=paid-media' },
      { title: 'I manage campaigns for several clients', desc: 'Organize your clients, projects and campaigns, and track each client independently.', to: '/register?journey=multi-client&module=paid-media' },
      { title: 'I need paid-media services', desc: 'Pick management, optimization, tracking, analysis or reporting services.', action: 'reveal-services' },
      { title: 'I need influencers or UGC content', desc: 'Send your campaign details and track content, execution and deliverables from your account.', to: '/requests/new?module=influencer-marketing' },
    ],
    login: {
      helper: 'Log in to manage your campaigns, or follow your requests, quotes, invoices and execution.',
      actions: [
        { label: 'Log in', to: '/login' },
        { label: 'Track my requests', to: '/login' },
      ],
    },
  },
  services: {
    hint: 'Select one or more services, then continue your request.',
    popular: 'Popular',
    selected: 'Selected services',
    clearAll: 'Clear all',
    continueCta: 'Continue your request',
    viewAll: 'View all services',
    drawerTitle: 'All paid-media services',
    drawerSubtitle: 'Search or filter by category, and pick what fits your request.',
    search: 'Search services…',
    allCategories: 'All categories',
    custom: 'Custom request',
    customDesc: "Didn't find what you need? Send a custom request and we'll reach out.",
    empty: 'No services available right now.',
    close: 'Done',
    errorTitle: "Couldn't load services",
    errorDesc: 'Something went wrong fetching the service list. Please try again.',
    retry: 'Retry',
  },
  steps: {
    title: 'How it works',
    subtitle: 'From connection to report in four steps.',
    items: [
      { title: 'Connect accounts', desc: 'Secure connection for your ad accounts across platforms.' },
      { title: 'Organize campaigns', desc: 'Clients, projects and campaigns — each project independent.' },
      { title: 'Track performance', desc: 'Budgets, results and KPIs by campaign objective.' },
      { title: 'Get reports', desc: 'Clear reports and automatic alerts for decisions.' },
    ],
  },
  serviceAreas: {
    title: 'Services',
    subtitle: "If you'd rather not manage it yourself, request the service you need and we'll help deliver it.",
    cta: 'Request a service',
    items: [
      { title: 'Campaign management', desc: 'Launch and run your paid campaigns across platforms.' },
      { title: 'Performance optimization', desc: 'Lower cost per result and raise return from your campaigns.' },
      { title: 'Tracking & measurement', desc: 'Set up pixels, events and conversions for accurate results.' },
      { title: 'Analysis & audit', desc: 'Audit your accounts, analyze performance and find opportunities.' },
      { title: 'Reporting', desc: 'Clear reports by campaign objective, ready to share.' },
      { title: 'Consulting', desc: 'Sessions and reviews for ad strategy and platform selection.' },
    ],
  },
  features: {
    title: 'Key features',
    subtitle: 'Clear tools built on your real account data.',
    items: [
      { title: 'Unified tracking', desc: 'Your campaigns, platforms and results in one dashboard.' },
      { title: 'Performance comparison', desc: 'Compare campaigns and platforms, and spot the best fast.' },
      { title: 'Reports', desc: 'Objective-based reports, ready for a client or a team.' },
      { title: 'Budgets', desc: 'Monitor spend pace and forecast overruns early.' },
      { title: 'Creatives', desc: 'Compare ad performance and see what works best.' },
      { title: 'Alerts', desc: 'Get alerted on rising costs or a stalled sync.' },
    ],
  },
  platforms: {
    title: 'Supported platforms',
    subtitle: 'Bring your campaign data from the main platforms into one place.',
    note: 'All six platforms are supported — you connect your own ad account, and no figure appears before a real first sync.',
    items: [
      { label: 'Snapchat Ads', desc: 'Awareness, conversion and catalogue campaigns', status: 'Available to connect', tone: 'ok' },
      { label: 'TikTok Ads', desc: 'Video and short-form campaigns', status: 'Available to connect', tone: 'ok' },
      { label: 'Meta (Facebook · Instagram)', desc: 'Campaigns, ad sets, ads and daily results', status: 'Available to connect', tone: 'ok' },
      { label: 'Google Ads', desc: 'Search, Shopping and Performance Max', status: 'Available to connect', tone: 'ok' },
      { label: 'X (Twitter) Ads', desc: 'Engagement and reach campaigns', status: 'Available to connect', tone: 'ok' },
      { label: 'LinkedIn Ads', desc: 'B2B and lead-generation campaigns', status: 'Available to connect', tone: 'ok' },
    ],
  },
  reports: {
    title: 'Reports & alerts',
    subtitle: 'Share-ready reports, and alerts that reach you at the right time.',
    formatsLabel: 'Report formats & outputs',
    formats: ['PDF', 'XLSX', 'CSV', 'Weekly', 'Monthly', 'Executive', 'Secure links', 'Scheduled email'],
    alertsLabel: 'Smart alerts',
    alerts: ['Rising cost per result', 'Approaching budget overrun', 'Sync stopped', 'Active campaign stopped'],
  },
  finalCta: {
    title: 'Track and run your campaigns from one place',
    subtitle: 'Create your account in minutes, or send a service request and track it via a secure link.',
    start: 'Create account',
    request: 'Request a service',
  },
  footer: {
    tagline: 'Paid advertising management — clients, projects, campaigns, analytics and reports in one place.',
    product: 'Product',
    links: [
      { label: 'Create account', to: '/register' },
      { label: 'Log in', to: '/login' },
      { label: 'Request a service', to: '/requests/new' },
      { label: 'Track my requests', to: '/login' },
    ],
    legal: ['Privacy', 'Terms', 'Support'],
    contactLabel: 'Contact',
    email: 'info@CampaignsHub.io',
    groups: [
      {
        title: 'Product',
        links: [
          { label: 'Create an account', to: '/register' },
          { label: 'Log in', to: '/login' },
          { label: 'Request a service', to: '/requests/new' },
          { label: 'Track my requests', to: '/login' },
        ],
      },
      {
        title: 'Company',
        links: [
          { label: 'About', to: '/about' },
          { label: 'Contact', to: '/contact' },
          { label: 'Support', to: '/support' },
          { label: 'FAQ', to: '/faq' },
        ],
      },
      {
        title: 'Policies',
        links: [
          { label: 'Privacy policy', to: '/privacy' },
          { label: 'Terms of service', to: '/terms' },
          { label: 'Data processing', to: '/data-processing' },
          { label: 'Cookies', to: '/cookies' },
          { label: 'Security', to: '/security' },
        ],
      },
    ],
    rights: 'All rights reserved',
  },
}

/**
 * The offers this page may actually make (INFL-OFF-001).
 *
 * The influencer/UGC card is removed from the hero's start paths and from the "how do you want to
 * begin?" list while the sub-system is switched off. Filtered HERE, once, because the same card is
 * rendered by three surfaces — the hero, its collapsed alternatives strip, and the options block
 * further down the page — and a card removed from two of them is a card the visitor still finds.
 *
 * The wording stays in the file. It is not dead: it is the copy this card comes back with, and
 * deleting it would mean writing it again from memory when the service reopens.
 */
function offeredCopy(copy: HomeCopy): HomeCopy {
  if (features.influencersUgc) return copy

  return {
    ...copy,
    start: {
      ...copy.start,
      paths: copy.start.paths.filter((p) => p.key !== 'influencer'),
    },
    options: {
      ...copy.options,
      cards: copy.options.cards.filter((c) => !c.to?.includes('influencer')),
    },
  }
}

export const HOME_COPY: Record<Locale, HomeCopy> = { ar: offeredCopy(ar), en: offeredCopy(en) }
