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
  platforms: { title: string; subtitle: string; note: string; items: { label: string; status: string; tone: 'ok' | 'dev' | 'await' | 'soon' }[] }
  reports: { title: string; subtitle: string; formatsLabel: string; formats: string[]; alertsLabel: string; alerts: string[] }
  finalCta: { title: string; subtitle: string; start: string; request: string }
  footer: { tagline: string; product: string; links: { label: string; to: string }[]; legal: string[]; rights: string }
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
        { label: 'متابعة طلباتي', to: '/client/login' },
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
    note: 'نعرض حالة كل منصة بصدق — بعضها متاح، وبعضها قيد التطوير أو بانتظار بيانات الربط.',
    items: [
      { label: 'Meta (Facebook · Instagram)', status: 'بانتظار بيانات الربط', tone: 'await' },
      { label: 'Google Ads', status: 'بانتظار بيانات الربط', tone: 'await' },
      { label: 'TikTok Ads', status: 'قيد التطوير', tone: 'dev' },
      { label: 'Snapchat Ads', status: 'قيد التطوير', tone: 'dev' },
      { label: 'X (Twitter) Ads', status: 'قريبًا', tone: 'soon' },
      { label: 'LinkedIn Ads', status: 'قريبًا', tone: 'soon' },
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
    title: 'ابدأ إدارة حملاتك الإعلانية المدفوعة اليوم',
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
      { label: 'متابعة طلباتي', to: '/client/login' },
    ],
    legal: ['الخصوصية', 'الشروط', 'الدعم'],
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
        { label: 'Track my requests', to: '/client/login' },
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
    note: 'We show each platform’s status honestly — some available, some in development or awaiting connection credentials.',
    items: [
      { label: 'Meta (Facebook · Instagram)', status: 'Awaiting credentials', tone: 'await' },
      { label: 'Google Ads', status: 'Awaiting credentials', tone: 'await' },
      { label: 'TikTok Ads', status: 'In development', tone: 'dev' },
      { label: 'Snapchat Ads', status: 'In development', tone: 'dev' },
      { label: 'X (Twitter) Ads', status: 'Coming soon', tone: 'soon' },
      { label: 'LinkedIn Ads', status: 'Coming soon', tone: 'soon' },
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
    title: 'Start managing your paid advertising today',
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
      { label: 'Track my requests', to: '/client/login' },
    ],
    legal: ['Privacy', 'Terms', 'Support'],
    rights: 'All rights reserved',
  },
}

export const HOME_COPY: Record<Locale, HomeCopy> = { ar, en }
