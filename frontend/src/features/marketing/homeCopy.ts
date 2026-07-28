/**
 * Public homepage copy (ar + en). Kept as data so the marketing page stays declarative and the
 * official terminology ("إدارة الحملات الإعلانية المدفوعة / Paid Advertising Management") is used
 * consistently — never the loose synonyms "الحملات الرقمية / الممولة" as the module name.
 *
 * Structure is intentionally lean: one hero, one decision section (the "How do you want to use
 * CampaignsHub?" journey chooser), one value band, one steps band, one features grid, one combined
 * integrations+reports band, one final CTA. The journeys live ONLY in the decision section — they
 * are never duplicated as another card grid elsewhere on the page.
 */
export type Locale = 'ar' | 'en'

export interface HomeCopy {
  dir: 'rtl' | 'ltr'
  nav: { features: string; how: string; usage: string; integrations: string; login: string; start: string; request: string; clientLogin: string }
  hero: { eyebrow: string; title: string; subtitle: string; support: string; ctaStart: string; ctaRequest: string; points: string[]; demoTag: string }
  /** Two clearly-separated commercial tracks — SaaS subscription vs one-off service request. Never mixed. */
  tracks: { title: string; subtitle: string; saas: { label: string; steps: string[] }; service: { label: string; steps: string[] } }
  previewTabs: { key: string; label: string }[]
  coreValue: { title: string; subtitle: string; pillars: { title: string; desc: string }[] }
  steps: { title: string; subtitle: string; items: { title: string; desc: string }[] }
  features: { title: string; subtitle: string; items: { title: string; desc: string }[] }
  decision: {
    title: string
    subtitle: string
    /** A journey either navigates (`to`) or reveals the inline paid-services selector (`action`). */
    cards: { title: string; desc: string; cta: string; to?: string; action?: 'reveal-services' }[]
    accounts: { label: string; actions: { label: string; to: string; variant: 'primary' | 'secondary'; desc: string }[] }
  }
  /** Strings for the inline paid-media services selector revealed inside the hero side card. */
  services: {
    hint: string
    popular: string
    selected: string
    clearAll: string
    continueCta: string
    viewAll: string
    drawerTitle: string
    drawerSubtitle: string
    search: string
    allCategories: string
    custom: string
    customDesc: string
    empty: string
    close: string
    errorTitle: string
    errorDesc: string
    retry: string
  }
  combined: {
    title: string
    subtitle: string
    integLabel: string
    statuses: { label: string; tone: 'ok' | 'dev' | 'await' | 'soon' }[]
    flow: string[]
    reportsLabel: string
    formats: string[]
    basisLabel: string
    basis: string[]
  }
  finalCta: { title: string; subtitle: string; start: string; request: string }
  footer: { tagline: string; product: string; links: { label: string; to: string }[]; legal: string[]; rights: string }
}

const ar: HomeCopy = {
  dir: 'rtl',
  nav: { features: 'المميزات', how: 'كيف يعمل', usage: 'المسارات', integrations: 'التكاملات والتقارير', login: 'تسجيل الدخول', start: 'ابدأ الآن', request: 'اطلب خدمة', clientLogin: 'متابعة طلباتي' },
  hero: {
    eyebrow: 'منصة إدارة الحملات الإعلانية المدفوعة',
    title: 'كل حملاتك الإعلانية المدفوعة في مكان واحد',
    subtitle: 'أدر حملاتك بنفسك عبر مساحة عمل احترافية، أو اختر خدمات متخصصة في الإدارة والتحسين والتتبع والتحليل والتقارير وتابع تنفيذها من بوابة العميل.',
    support: 'اشتراك لإدارة حملاتك، وبوابة متكاملة لطلب الخدمات ومتابعة التنفيذ.',
    ctaStart: 'ابدأ إدارة حملاتك',
    ctaRequest: 'أرسل طلب خدمة',
    points: ['إدارة موحدة لجميع المنصات والحملات', 'تحليلات وتقارير حسب هدف الحملة', 'بيانات من مصادر الربط المعتمدة', 'طلبات وعروض وفواتير ومتابعة في مكان واحد'],
    demoTag: 'معاينة توضيحية ببيانات تجريبية',
  },
  tracks: {
    title: 'مساران للعمل مع CampaignsHub',
    subtitle: 'اشتراك لإدارة حملاتك، وبوابة متكاملة لطلب الخدمات ومتابعة التنفيذ.',
    saas: { label: 'اشتراك SaaS', steps: ['أنشئ مساحة عمل', 'اربط منصاتك', 'أدر الحملات', 'تابع التحليلات', 'أنشئ التقارير', 'استقبل التنبيهات'] },
    service: { label: 'طلب خدمة', steps: ['اختر الخدمات', 'أرسل الطلب', 'استلم عرض السعر', 'وافق وادفع', 'تابع التنفيذ', 'استلم التقارير'] },
  },
  previewTabs: [
    { key: 'dashboard', label: 'لوحة التحكم' },
    { key: 'campaigns', label: 'الحملات' },
    { key: 'analytics', label: 'التحليلات' },
    { key: 'reports', label: 'التقارير' },
    { key: 'connections', label: 'الربط' },
  ],
  coreValue: {
    title: 'لماذا CampaignsHub',
    subtitle: 'قيمة واحدة واضحة: كل ما تحتاجه لإدارة الإعلانات المدفوعة باحتراف، دون فوضى الأدوات.',
    pillars: [
      { title: 'مركز موحّد', desc: 'الحملات والمنصات والعملاء في مكان واحد بعزل كامل لكل مشروع.' },
      { title: 'بيانات فعلية', desc: 'مقاييس من API المنصات مع مصدر واضح لكل رقم — لا شاشات تعريفية.' },
      { title: 'تقارير حسب الهدف', desc: 'مؤشرات وتقارير تتغيّر بحسب هدف كل حملة، لا مقياس موحّد للجميع.' },
    ],
  },
  steps: {
    title: 'كيف يعمل',
    subtitle: 'من الربط إلى التقرير في أربع خطوات.',
    items: [
      { title: 'اربط منصاتك', desc: 'ربط آمن عبر OAuth لحسابات الإعلانات.' },
      { title: 'نظّم عملاءك', desc: 'عملاء ومشاريع وحملات بعزل كامل.' },
      { title: 'تابع الأداء', desc: 'ميزانيات ونتائج ومؤشرات حسب الهدف.' },
      { title: 'أنشئ التقارير', desc: 'تقارير احترافية وتنبيهات تلقائية.' },
    ],
  },
  features: {
    title: 'كل ما تحتاجه لإدارة الحملات',
    subtitle: 'أدوات فعلية مبنية على بيانات المنصات.',
    items: [
      { title: 'إدارة الحملات', desc: 'تابع حملاتك ومنصاتك من مكان واحد.' },
      { title: 'التحليلات', desc: 'مؤشرات واضحة تكشف الفرص والمخاطر.' },
      { title: 'التقارير', desc: 'تقارير حسب الهدف تفصل العميل عن الداخلي.' },
      { title: 'الميزانيات', desc: 'راقب سرعة الصرف وتوقّع التجاوز مبكرًا.' },
      { title: 'المحتويات الإعلانية', desc: 'قارن أداء الإعلانات ببيانات فعلية.' },
      { title: 'التنبيهات', desc: 'تنبيه عند ارتفاع التكلفة أو فشل المزامنة.' },
    ],
  },
  decision: {
    title: 'كيف تريد استخدام CampaignsHub؟',
    subtitle: 'اختر المسار المناسب لاحتياجك، وسنهيئ لك التسجيل أو الطلب تلقائيًا.',
    cards: [
      { title: 'أدير حملاتي بنفسي', desc: 'للميديا باير والمستقل والعلامة التجارية وفريق التسويق الداخلي.', cta: 'أنشئ مساحة عمل', to: '/register?journey=self-managed&module=paid-media' },
      { title: 'أدير حملات عدة عملاء', desc: 'للوكالات والمستقلين الذين يديرون عملاء ومشاريع متعددة.', cta: 'ابدأ كوكالة', to: '/register?journey=agency&module=paid-media' },
      { title: 'أحتاج خدمات إعلانية', desc: 'اختر خدمات الإدارة والتحسين والتحليل والتتبع والتقارير والاستشارات.', cta: 'استعرض الخدمات', action: 'reveal-services' },
      { title: 'أحتاج حملة مؤثرين أو UGC', desc: 'لطلب حملة مؤثرين أو محتوى UGC ومتابعة التنفيذ والتسليمات.', cta: 'أرسل طلب مؤثرين', to: '/requests/new?module=influencer-marketing' },
    ],
    accounts: {
      label: 'لديك حساب بالفعل؟',
      actions: [
        { label: 'دخول مساحة العمل', to: '/login', variant: 'secondary', desc: 'دخول مساحة العمل: للمشتركين والوكالات والشركات والمستقلين.' },
        { label: 'متابعة طلباتي', to: '/client/login', variant: 'secondary', desc: 'متابعة طلباتي: للعملاء الذين أرسلوا طلب خدمة ويريدون متابعة العرض والفاتورة والتنفيذ.' },
      ],
    },
  },
  services: {
    hint: 'اختر خدمة أو أكثر ثم أكمل تفاصيل طلبك.',
    popular: 'الأكثر طلبًا',
    selected: 'الخدمات المختارة',
    clearAll: 'مسح الكل',
    continueCta: 'أكمل تفاصيل طلبك',
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
  combined: {
    title: 'التكاملات والتقارير',
    subtitle: 'ربط صادق بمصادر فعلية، وتقارير جاهزة للمشاركة — بوضع معلن لكل مصدر.',
    integLabel: 'حالة المصادر',
    statuses: [
      { label: 'Meta Ads · Awaiting Credentials', tone: 'await' },
      { label: 'Google Ads · Awaiting Credentials', tone: 'await' },
      { label: 'TikTok Ads · In Development', tone: 'dev' },
      { label: 'Snapchat Ads · In Development', tone: 'dev' },
      { label: 'GA4 · Coming Soon', tone: 'soon' },
      { label: 'Sandbox · Available', tone: 'ok' },
    ],
    flow: ['اربط الحساب', 'اختر الحساب الإعلاني', 'زامن الحملات', 'تظهر البيانات في كل مكان'],
    reportsLabel: 'صيغ ومخرجات التقارير',
    formats: ['PDF', 'XLSX', 'CSV', 'أسبوعية', 'شهرية', 'تنفيذية', 'روابط آمنة', 'إرسال مجدول'],
    basisLabel: 'كل تقرير يعتمد على',
    basis: ['هدف الحملة', 'المنصات المرتبطة', 'بيانات API', 'الفترة والعملة'],
  },
  finalCta: {
    title: 'ابدأ إدارة حملاتك الإعلانية المدفوعة اليوم',
    subtitle: 'أنشئ حسابك خلال دقائق، أو أرسل طلب خدمة وتابعه عبر رابط آمن.',
    start: 'ابدأ إدارة حملاتك',
    request: 'أرسل طلب خدمة',
  },
  footer: {
    tagline: 'منصة إدارة الحملات الإعلانية المدفوعة — العملاء والمشاريع والحملات والتحليلات والتقارير في مكان واحد.',
    product: 'المنتج',
    links: [
      { label: 'إنشاء حساب', to: '/register' },
      { label: 'تسجيل الدخول', to: '/login' },
      { label: 'إرسال طلب', to: '/requests/new' },
      { label: 'تتبع طلب', to: '/requests/track' },
    ],
    legal: ['الخصوصية', 'الشروط', 'الدعم'],
    rights: 'جميع الحقوق محفوظة',
  },
}

const en: HomeCopy = {
  dir: 'ltr',
  nav: { features: 'Features', how: 'How it works', usage: 'Paths', integrations: 'Integrations & reports', login: 'Log in', start: 'Get started', request: 'Request a service', clientLogin: 'Track my requests' },
  hero: {
    eyebrow: 'Paid Advertising Management platform',
    title: 'All your paid ad campaigns in one place',
    subtitle: 'Run your campaigns yourself in a professional workspace, or choose specialist services for management, optimization, tracking, analysis and reporting — and follow their execution from the client portal.',
    support: 'A subscription to run your campaigns, and an integrated portal to request services and follow execution.',
    ctaStart: 'Start managing campaigns',
    ctaRequest: 'Request a service',
    points: ['Unified management across all platforms and campaigns', 'Analytics and reports by campaign objective', 'Data from approved connection sources', 'Requests, quotes, invoices and tracking in one place'],
    demoTag: 'Illustrative preview with demo data',
  },
  tracks: {
    title: 'Two ways to work with CampaignsHub',
    subtitle: 'A subscription to run your campaigns, and an integrated portal to request services and follow execution.',
    saas: { label: 'SaaS subscription', steps: ['Create a workspace', 'Connect your platforms', 'Run campaigns', 'Track analytics', 'Build reports', 'Receive alerts'] },
    service: { label: 'Service request', steps: ['Pick services', 'Send the request', 'Receive a quote', 'Approve & pay', 'Follow execution', 'Receive reports'] },
  },
  previewTabs: [
    { key: 'dashboard', label: 'Dashboard' },
    { key: 'campaigns', label: 'Campaigns' },
    { key: 'analytics', label: 'Analytics' },
    { key: 'reports', label: 'Reports' },
    { key: 'connections', label: 'Connections' },
  ],
  coreValue: {
    title: 'Why CampaignsHub',
    subtitle: 'One clear promise: everything you need to run paid advertising professionally, without tool sprawl.',
    pillars: [
      { title: 'One unified hub', desc: 'Campaigns, platforms and clients in one place, fully isolated per project.' },
      { title: 'Real data', desc: 'Metrics from platform APIs with a clear source for every number — not brochure screens.' },
      { title: 'Objective-based reports', desc: 'KPIs and reports that change with each objective — no single metric for all.' },
    ],
  },
  steps: {
    title: 'How it works',
    subtitle: 'From connection to report in four steps.',
    items: [
      { title: 'Connect platforms', desc: 'Secure OAuth for your ad accounts.' },
      { title: 'Organize clients', desc: 'Clients, projects and campaigns, fully isolated.' },
      { title: 'Track performance', desc: 'Budgets, results and objective-based KPIs.' },
      { title: 'Build reports', desc: 'Professional reports and automatic alerts.' },
    ],
  },
  features: {
    title: 'Everything you need to run campaigns',
    subtitle: 'Real tools built on platform data.',
    items: [
      { title: 'Campaign management', desc: 'Track campaigns and platforms in one place.' },
      { title: 'Analytics', desc: 'Clear signals that surface opportunities and risks.' },
      { title: 'Reports', desc: 'Objective-based reports separating client from internal.' },
      { title: 'Budgets', desc: 'Monitor spend pace and forecast overruns early.' },
      { title: 'Creatives', desc: 'Compare ad performance on real API data.' },
      { title: 'Alerts', desc: 'Get alerted on rising costs or sync failures.' },
    ],
  },
  decision: {
    title: 'How do you want to use CampaignsHub?',
    subtitle: 'Pick the path that fits your need, and we’ll set up your registration or request automatically.',
    cards: [
      { title: 'I run my own campaigns', desc: 'For media buyers, freelancers, brands, and in-house marketing teams.', cta: 'Create a workspace', to: '/register?journey=self-managed&module=paid-media' },
      { title: "I manage several clients' campaigns", desc: 'For agencies and freelancers running multiple clients and projects.', cta: 'Start as an agency', to: '/register?journey=agency&module=paid-media' },
      { title: 'I need paid-media services', desc: 'Pick management, optimization, analysis, tracking, reporting and consulting services.', cta: 'Browse services', action: 'reveal-services' },
      { title: 'I need an influencer or UGC campaign', desc: 'To request an influencer or UGC campaign and follow execution and deliverables.', cta: 'Send an influencer request', to: '/requests/new?module=influencer-marketing' },
    ],
    accounts: {
      label: 'Do you already have an account?',
      actions: [
        { label: 'Workspace login', to: '/login', variant: 'secondary', desc: 'Workspace login: for subscribers, agencies, companies and freelancers.' },
        { label: 'Track my requests', to: '/client/login', variant: 'secondary', desc: 'Track my requests: for clients who sent a service request and want to follow the quote, invoice and execution.' },
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
  combined: {
    title: 'Integrations & reports',
    subtitle: 'Honest connections to real sources, and share-ready reports — with an explicit status for each.',
    integLabel: 'Source status',
    statuses: [
      { label: 'Meta Ads · Awaiting Credentials', tone: 'await' },
      { label: 'Google Ads · Awaiting Credentials', tone: 'await' },
      { label: 'TikTok Ads · In Development', tone: 'dev' },
      { label: 'Snapchat Ads · In Development', tone: 'dev' },
      { label: 'GA4 · Coming Soon', tone: 'soon' },
      { label: 'Sandbox · Available', tone: 'ok' },
    ],
    flow: ['Connect account', 'Pick ad account', 'Sync campaigns', 'Data appears everywhere'],
    reportsLabel: 'Report formats & outputs',
    formats: ['PDF', 'XLSX', 'CSV', 'Weekly', 'Monthly', 'Executive', 'Secure links', 'Scheduled email'],
    basisLabel: 'Every report is based on',
    basis: ['Campaign objective', 'Connected platforms', 'API data', 'Range & currency'],
  },
  finalCta: {
    title: 'Start managing your paid advertising today',
    subtitle: 'Create your account in minutes, or send a service request and track it via a secure link.',
    start: 'Start managing campaigns',
    request: 'Request a service',
  },
  footer: {
    tagline: 'Paid Advertising Management platform — clients, projects, campaigns, analytics and reports in one place.',
    product: 'Product',
    links: [
      { label: 'Create account', to: '/register' },
      { label: 'Log in', to: '/login' },
      { label: 'Send a request', to: '/requests/new' },
      { label: 'Track a request', to: '/requests/track' },
    ],
    legal: ['Privacy', 'Terms', 'Support'],
    rights: 'All rights reserved',
  },
}

export const HOME_COPY: Record<Locale, HomeCopy> = { ar, en }
