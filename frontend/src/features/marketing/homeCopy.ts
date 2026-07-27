/**
 * Public homepage copy (ar + en). Kept as data so the marketing page stays declarative and the
 * official terminology ("إدارة الحملات الإعلانية المدفوعة / Paid Advertising Management") is used
 * consistently — never the loose synonyms "الحملات الرقمية / الممولة" as the module name.
 */
export type Locale = 'ar' | 'en'

export interface HomeCopy {
  dir: 'rtl' | 'ltr'
  nav: { features: string; how: string; analytics: string; reports: string; integrations: string; login: string; start: string; request: string }
  hero: { eyebrow: string; title: string; subtitle: string; ctaStart: string; ctaRequest: string; ctaExplore: string; points: string[]; demoTag: string }
  previewTabs: { key: string; label: string }[]
  journeys: { title: string; subtitle: string; items: { title: string; desc: string; cta: string; to: string; soon?: boolean }[] }
  features: { title: string; subtitle: string; items: { title: string; desc: string }[] }
  objectives: { title: string; subtitle: string; note: string; items: string[]; perks: string[] }
  reports: { title: string; subtitle: string; formats: string[]; basis: { label: string; items: string[] } }
  integrations: { title: string; subtitle: string; statuses: { label: string; tone: 'ok' | 'dev' | 'await' | 'soon' }[]; flow: string[] }
  automation: { title: string; subtitle: string; items: string[] }
  request: { title: string; subtitle: string; types: string[]; cta: string }
  audience: { title: string; items: string[] }
  finalCta: { title: string; subtitle: string; start: string; request: string }
  footer: { tagline: string; product: string; links: { label: string; to: string }[]; legal: string[]; rights: string }
}

const ar: HomeCopy = {
  dir: 'rtl',
  nav: { features: 'المميزات', how: 'كيف يعمل', analytics: 'التحليلات', reports: 'التقارير', integrations: 'التكاملات', login: 'تسجيل الدخول', start: 'ابدأ الآن', request: 'أرسل طلب خدمة' },
  hero: {
    eyebrow: 'منصة تشغيل متكاملة لإدارة الحملات الإعلانية المدفوعة',
    title: 'أدر حملاتك الإعلانية المدفوعة من منصة تشغيل واحدة',
    subtitle: 'CampaignsHub يجمع العملاء والمشاريع والحملات والمنصات والتحليلات والتقارير والتنبيهات في بيئة واحدة مصممة لإدارة أعمال الميديا باينج باحتراف.',
    ctaStart: 'ابدأ إدارة حملاتك', ctaRequest: 'أرسل طلب إدارة حملة', ctaExplore: 'استعرض النظام',
    points: ['متابعة جميع المنصات من مكان واحد', 'إدارة العملاء والمشاريع والميزانيات', 'تحليلات حسب هدف الحملة', 'تقارير احترافية للعملاء', 'تنبيهات ومتابعة يومية', 'بيانات API فعلية لكل منصة'],
    demoTag: 'معاينة توضيحية ببيانات تجريبية',
  },
  previewTabs: [
    { key: 'dashboard', label: 'لوحة التحكم' }, { key: 'campaigns', label: 'الحملات' }, { key: 'analytics', label: 'التحليلات' },
    { key: 'reports', label: 'التقارير' }, { key: 'connections', label: 'الربط' }, { key: 'alerts', label: 'التنبيهات' }, { key: 'requests', label: 'الطلبات' },
  ],
  journeys: {
    title: 'كيف تريد استخدام CampaignsHub؟',
    subtitle: 'اختر المسار الأنسب لك — لكل مسار تجربة مصممة له.',
    items: [
      { title: 'أدير حملاتي المدفوعة بنفسي', desc: 'أنشئ مساحة عمل لمتابعة الحملات والميزانيات والتحليلات والتقارير.', cta: 'إنشاء حساب', to: '/register' },
      { title: 'أدير حملات عدة عملاء', desc: 'نظّم العملاء والمشاريع والحملات ومصادر البيانات في مكان واحد.', cta: 'إنشاء مساحة وكالة', to: '/register' },
      { title: 'أحتاج خدمة إدارة حملات', desc: 'أرسل تفاصيل طلبك دون إنشاء حساب، وتابع حالته عبر رابط آمن.', cta: 'إرسال طلب خدمة', to: '/requests/new' },
      { title: 'إدارة حملات المؤثرين وUGC', desc: 'إدارة الترشيحات والتعاونات والمحتوى والموافقات وتقارير التعاون.', cta: 'قريبًا', to: '/register', soon: true },
      { title: 'لدي حساب بالفعل', desc: 'ادخل إلى مساحة عملك وتابع من حيث توقفت.', cta: 'تسجيل الدخول', to: '/login' },
    ],
  },
  features: {
    title: 'كل ما تحتاجه لإدارة الحملات باحتراف',
    subtitle: 'أدوات فعلية مبنية على بيانات المنصات، وليست شاشات تعريفية.',
    items: [
      { title: 'إدارة الحملات', desc: 'تابع حملاتك ومنصاتك من مكان واحد مع عزل كامل لكل مشروع وعميل.' },
      { title: 'التحليلات', desc: 'حوّل بيانات الأداء إلى مؤشرات واضحة تكشف الفرص والمخاطر بسرعة.' },
      { title: 'التقارير', desc: 'تقارير مخصصة حسب هدف الحملة مع فصل تقارير العميل عن التحليلات الداخلية.' },
      { title: 'الميزانيات', desc: 'راقب سرعة الصرف والمتبقي وتوقع نهاية الميزانية قبل التجاوز.' },
      { title: 'المحتويات الإعلانية', desc: 'قارن أداء الإعلانات باستخدام بيانات API الفعلية لكل منصة.' },
      { title: 'التنبيهات', desc: 'تنبيهات عند ارتفاع التكلفة أو انخفاض الأداء أو فشل المزامنة.' },
      { title: 'العملاء والمشاريع', desc: 'نظّم العملاء والمشاريع والطلبات والملفات والمهام في مساحة واحدة.' },
      { title: 'مصادر البيانات', desc: 'اربط المنصات واحتفظ بمصدر كل رقم بوضوح.' },
    ],
  },
  objectives: {
    title: 'مؤشرات وتقارير حسب هدف الحملة',
    subtitle: 'النظام لا يستخدم مقاييس موحدة لكل الأهداف — لكل هدف مؤشراته ومساره وتقاريره.',
    note: 'ROAS ليس المؤشر الرئيسي لكل الحملات؛ يتغير المقياس بحسب الهدف.',
    items: ['المبيعات والتحويلات', 'العملاء المحتملون', 'الوعي والوصول', 'الزيارات', 'التفاعل والاهتمام', 'تحميلات التطبيقات', 'مشاهدات الفيديو', 'زيارات المتجر والفعاليات', 'أهداف مخصصة'],
    perks: ['KPIs مناسبة للهدف', 'Funnel مختلف', 'تقارير متخصصة', 'تحليل محتويات مختلف', 'توصيات مرتبطة بالهدف'],
  },
  reports: {
    title: 'تقارير احترافية تعتمد على بيانات فعلية',
    subtitle: 'من التحليلات الداخلية إلى تقارير العميل التنفيذية.',
    formats: ['أسبوعية', 'شهرية', 'تنفيذية', 'تقارير عميل', 'تحليلات داخلية', 'PDF', 'XLSX', 'CSV', 'روابط آمنة', 'إرسال مجدول بالبريد'],
    basis: { label: 'كل تقرير يعتمد على', items: ['هدف الحملة', 'المنصات المرتبطة', 'بيانات API', 'الفترة', 'مصدر الحقيقة', 'الإسناد', 'العملة والمنطقة الزمنية', 'الملاحظات والتوصيات المعتمدة'] },
  },
  integrations: {
    title: 'تكاملات ومصادر بيانات بوضع صادق',
    subtitle: 'لا نعرض أي تكامل كفعلي قبل نجاح OAuth والمزامنة.',
    statuses: [
      { label: 'Meta Ads · Awaiting Credentials', tone: 'await' }, { label: 'Google Ads · Awaiting Credentials', tone: 'await' },
      { label: 'TikTok Ads · In Development', tone: 'dev' }, { label: 'Snapchat Ads · In Development', tone: 'dev' },
      { label: 'GA4 · Coming Soon', tone: 'soon' }, { label: 'Sandbox · Available', tone: 'ok' },
    ],
    flow: ['ربط الحساب', 'اختيار الحساب الإعلاني', 'مزامنة الحملات', 'مزامنة الإعلانات والمحتويات', 'توحيد المقاييس', 'ظهور البيانات في الحملات والتحليلات والتقارير والتنبيهات'],
  },
  automation: {
    title: 'الأتمتة والتنبيهات',
    subtitle: 'حوّل المتابعة اليومية إلى تدفقات عمل منظمة.',
    items: ['تنبيهات الميزانية وسرعة الصرف', 'ارتفاع CPA أو CPL', 'انخفاض ROAS', 'فشل المزامنة أو انتهاء Token', 'جاهزية التقرير أو فشله', 'ملخصات يومية وأسبوعية'],
  },
  request: {
    title: 'تحتاج إلى إدارة حملة بدل استخدام النظام؟',
    subtitle: 'أرسل تفاصيل احتياجك، وتابع طلبك ضمن مسار واضح يبدأ بالمراجعة والتأهيل وينتقل إلى التنفيذ والتقارير.',
    types: ['إطلاق حملة إعلانية مدفوعة', 'إدارة حملة قائمة', 'تحسين الأداء', 'تدقيق حساب إعلاني', 'إعداد التتبع والتحويلات', 'ربط منصة أو مصدر بيانات', 'إنشاء تقرير', 'تحليل بيانات', 'استشارة', 'طلب مخصص', 'حملة مؤثرين أو UGC مستقبلًا'],
    cta: 'أرسل طلبك الآن',
  },
  audience: {
    title: 'لمن يناسب CampaignsHub؟',
    items: ['الميديا باير المستقل', 'وكالات التسويق الرقمي', 'فرق التسويق الداخلية', 'العلامات التجارية', 'الشركات التي تدير حملاتها ذاتيًا'],
  },
  finalCta: {
    title: 'ابدأ إدارة حملاتك الإعلانية المدفوعة اليوم',
    subtitle: 'أنشئ حسابك خلال دقائق، أو أرسل طلب خدمة وتابعه عبر رابط آمن.',
    start: 'ابدأ إدارة حملاتك', request: 'أرسل طلب خدمة',
  },
  footer: {
    tagline: 'منصة إدارة الحملات الإعلانية المدفوعة — العملاء والمشاريع والحملات والتحليلات والتقارير في مكان واحد.',
    product: 'المنتج',
    links: [
      { label: 'إدارة الحملات المدفوعة', to: '/register' }, { label: 'المؤثرون وUGC (قريبًا)', to: '/register' },
      { label: 'تسجيل الدخول', to: '/login' }, { label: 'إنشاء حساب', to: '/register' },
      { label: 'إرسال طلب', to: '/requests/new' }, { label: 'تتبع طلب', to: '/requests/track' },
    ],
    legal: ['الخصوصية', 'الشروط', 'الدعم'],
    rights: 'جميع الحقوق محفوظة',
  },
}

const en: HomeCopy = {
  dir: 'ltr',
  nav: { features: 'Features', how: 'How it works', analytics: 'Analytics', reports: 'Reports', integrations: 'Integrations', login: 'Log in', start: 'Get started', request: 'Request a service' },
  hero: {
    eyebrow: 'An all-in-one operations platform for Paid Advertising Management',
    title: 'Run your paid advertising from one operations platform',
    subtitle: 'CampaignsHub brings clients, projects, campaigns, platforms, analytics, reports and alerts into a single environment built to run media-buying professionally.',
    ctaStart: 'Start managing campaigns', ctaRequest: 'Request campaign management', ctaExplore: 'Explore the product',
    points: ['Track every platform in one place', 'Manage clients, projects and budgets', 'Objective-based analytics', 'Professional client reports', 'Daily alerts and monitoring', 'Real per-platform API data'],
    demoTag: 'Illustrative preview with demo data',
  },
  previewTabs: [
    { key: 'dashboard', label: 'Dashboard' }, { key: 'campaigns', label: 'Campaigns' }, { key: 'analytics', label: 'Analytics' },
    { key: 'reports', label: 'Reports' }, { key: 'connections', label: 'Connections' }, { key: 'alerts', label: 'Alerts' }, { key: 'requests', label: 'Requests' },
  ],
  journeys: {
    title: 'How do you want to use CampaignsHub?',
    subtitle: 'Pick the path that fits you — each one has a tailored experience.',
    items: [
      { title: 'I manage my own paid campaigns', desc: 'Create a workspace to track campaigns, budgets, analytics and reports.', cta: 'Create account', to: '/register' },
      { title: 'I manage several clients', desc: 'Organize clients, projects, campaigns and data sources in one place.', cta: 'Create agency workspace', to: '/register' },
      { title: 'I need campaign management as a service', desc: 'Send your request without an account and track it via a secure link.', cta: 'Request a service', to: '/requests/new' },
      { title: 'Influencer & UGC campaigns', desc: 'Manage shortlists, collaborations, content, approvals and reports.', cta: 'Coming soon', to: '/register', soon: true },
      { title: 'I already have an account', desc: 'Sign in to your workspace and pick up where you left off.', cta: 'Log in', to: '/login' },
    ],
  },
  features: {
    title: 'Everything you need to run campaigns professionally',
    subtitle: 'Real tools built on platform data — not brochure screens.',
    items: [
      { title: 'Campaign management', desc: 'Track campaigns and platforms in one place with full per-project and per-client isolation.' },
      { title: 'Analytics', desc: 'Turn performance data into clear signals that surface opportunities and risks fast.' },
      { title: 'Reports', desc: 'Objective-based reports that separate client reporting from internal analytics.' },
      { title: 'Budgets', desc: 'Monitor spend pace and forecast budget exhaustion before it happens.' },
      { title: 'Creatives', desc: 'Compare ad performance using each platform’s real API data.' },
      { title: 'Alerts', desc: 'Get alerted on rising costs, dropping performance or sync failures.' },
      { title: 'Clients & projects', desc: 'Organize clients, projects, requests, files and tasks in one workspace.' },
      { title: 'Data sources', desc: 'Connect platforms and keep the source of every number explicit.' },
    ],
  },
  objectives: {
    title: 'KPIs and reports by campaign objective',
    subtitle: 'The system doesn’t use one metric for every objective — each gets its own KPIs, funnel and reports.',
    note: 'ROAS is not the primary metric for every campaign; the metric changes with the objective.',
    items: ['Sales & conversions', 'Lead generation', 'Awareness & reach', 'Traffic', 'Engagement', 'App installs', 'Video views', 'Store visits & events', 'Custom objectives'],
    perks: ['Objective-fit KPIs', 'A different funnel', 'Specialized reports', 'Different creative analysis', 'Objective-linked recommendations'],
  },
  reports: {
    title: 'Professional reports built on real data',
    subtitle: 'From internal analytics to executive client reports.',
    formats: ['Weekly', 'Monthly', 'Executive', 'Client reports', 'Internal analytics', 'PDF', 'XLSX', 'CSV', 'Secure links', 'Scheduled email'],
    basis: { label: 'Every report is based on', items: ['Campaign objective', 'Connected platforms', 'API data', 'Date range', 'Source of truth', 'Attribution', 'Currency & time zone', 'Approved notes & recommendations'] },
  },
  integrations: {
    title: 'Integrations and data sources, stated honestly',
    subtitle: 'No integration is shown as live before OAuth and sync succeed.',
    statuses: [
      { label: 'Meta Ads · Awaiting Credentials', tone: 'await' }, { label: 'Google Ads · Awaiting Credentials', tone: 'await' },
      { label: 'TikTok Ads · In Development', tone: 'dev' }, { label: 'Snapchat Ads · In Development', tone: 'dev' },
      { label: 'GA4 · Coming Soon', tone: 'soon' }, { label: 'Sandbox · Available', tone: 'ok' },
    ],
    flow: ['Connect the account', 'Pick the ad account', 'Sync campaigns', 'Sync ads & creatives', 'Unify metrics', 'Data appears in campaigns, analytics, reports and alerts'],
  },
  automation: {
    title: 'Automation & alerts',
    subtitle: 'Turn daily monitoring into organized workflows.',
    items: ['Budget & spend-pace alerts', 'Rising CPA or CPL', 'Dropping ROAS', 'Sync failure or token expiry', 'Report ready or failed', 'Daily and weekly summaries'],
  },
  request: {
    title: 'Need campaign management instead of using the system?',
    subtitle: 'Send your requirements and track your request through a clear path from review and qualification to execution and reports.',
    types: ['Launch a paid campaign', 'Manage an existing campaign', 'Performance optimization', 'Ad-account audit', 'Tracking & conversions setup', 'Connect a platform or data source', 'Build a report', 'Data analysis', 'Consulting', 'Custom request', 'Influencer/UGC (future)'],
    cta: 'Send your request now',
  },
  audience: {
    title: 'Who is CampaignsHub for?',
    items: ['Freelance media buyers', 'Digital marketing agencies', 'In-house marketing teams', 'Brands', 'Companies running their own campaigns'],
  },
  finalCta: {
    title: 'Start managing your paid advertising today',
    subtitle: 'Create your account in minutes, or send a service request and track it via a secure link.',
    start: 'Start managing campaigns', request: 'Request a service',
  },
  footer: {
    tagline: 'Paid Advertising Management platform — clients, projects, campaigns, analytics and reports in one place.',
    product: 'Product',
    links: [
      { label: 'Paid Advertising', to: '/register' }, { label: 'Influencer & UGC (soon)', to: '/register' },
      { label: 'Log in', to: '/login' }, { label: 'Create account', to: '/register' },
      { label: 'Send a request', to: '/requests/new' }, { label: 'Track a request', to: '/requests/track' },
    ],
    legal: ['Privacy', 'Terms', 'Support'],
    rights: 'All rights reserved',
  },
}

export const HOME_COPY: Record<Locale, HomeCopy> = { ar, en }
