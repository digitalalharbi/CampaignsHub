/**
 * Public homepage copy (ar + en). Kept as data so the marketing page stays declarative and the
 * official terminology ("إدارة الحملات الإعلانية المدفوعة / Paid Advertising Management") is used
 * consistently — never the loose synonyms "الحملات الرقمية / الممولة" as the module name.
 *
 * Structure is intentionally lean: one hero, one value band, one steps band, one features grid,
 * one usage-selection grid, one combined integrations+reports band, one final CTA. No duplicated
 * or repeated-style card sections.
 */
export type Locale = 'ar' | 'en'

export interface HomeCopy {
  dir: 'rtl' | 'ltr'
  nav: { features: string; how: string; usage: string; integrations: string; login: string; start: string }
  hero: { eyebrow: string; title: string; subtitle: string; ctaStart: string; ctaRequest: string; points: string[]; demoTag: string }
  previewTabs: { key: string; label: string }[]
  coreValue: { title: string; subtitle: string; pillars: { title: string; desc: string }[] }
  steps: { title: string; subtitle: string; items: { title: string; desc: string }[] }
  features: { title: string; subtitle: string; items: { title: string; desc: string }[] }
  usage: { title: string; subtitle: string; cards: { title: string; desc: string; cta: string; to: string }[] }
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
  nav: { features: 'المميزات', how: 'كيف يعمل', usage: 'المسارات', integrations: 'التكاملات والتقارير', login: 'تسجيل الدخول', start: 'ابدأ الآن' },
  hero: {
    eyebrow: 'منصة إدارة الحملات الإعلانية المدفوعة',
    title: 'كل حملاتك الإعلانية المدفوعة في مكان واحد',
    subtitle: 'اربط منصاتك، تابع الميزانيات والنتائج، وأنشئ تقارير احترافية دون التنقل بين أدوات متعددة.',
    ctaStart: 'ابدأ إدارة حملاتك',
    ctaRequest: 'أرسل طلب خدمة',
    points: ['مركز موحّد لكل الحملات', 'عزل مستقل لكل عميل', 'مؤشرات حسب هدف الحملة', 'بيانات فعلية من الربط'],
    demoTag: 'معاينة توضيحية ببيانات تجريبية',
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
  usage: {
    title: 'كيف تريد استخدام CampaignsHub؟',
    subtitle: 'اختر المسار الأنسب لك، وسنأخذك مباشرة إلى الخطوة التالية.',
    cards: [
      { title: 'أدير حملاتي بنفسي', desc: 'للميديا باير وفرق التسويق الداخلية داخل الشركة.', cta: 'إنشاء حساب', to: '/register' },
      { title: 'أدير حملات عدة عملاء', desc: 'للوكالات التي تدير مشاريع وعملاء متعددين بعزل كامل.', cta: 'إنشاء مساحة عمل', to: '/register' },
      { title: 'أحتاج خدمة إدارة حملات', desc: 'أرسل طلبك وتابعه عبر رابط آمن دون إنشاء حساب.', cta: 'إرسال طلب', to: '/requests/new' },
    ],
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
  nav: { features: 'Features', how: 'How it works', usage: 'Paths', integrations: 'Integrations & reports', login: 'Log in', start: 'Get started' },
  hero: {
    eyebrow: 'Paid Advertising Management platform',
    title: 'All your paid ad campaigns in one place',
    subtitle: 'Connect your platforms, track budgets and results, and build professional reports — without switching between tools.',
    ctaStart: 'Start managing campaigns',
    ctaRequest: 'Request a service',
    points: ['One hub for every campaign', 'Isolated per client', 'Objective-based KPIs', 'Real data from connections'],
    demoTag: 'Illustrative preview with demo data',
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
  usage: {
    title: 'How do you want to use CampaignsHub?',
    subtitle: 'Pick the path that fits you and we’ll take you straight to the next step.',
    cards: [
      { title: 'I manage campaigns myself', desc: 'For media buyers and in-house marketing teams.', cta: 'Create account', to: '/register' },
      { title: 'I manage several clients', desc: 'For agencies running multiple projects and clients, fully isolated.', cta: 'Create workspace', to: '/register' },
      { title: 'I need campaign management', desc: 'Send your request and track it via a secure link — no account.', cta: 'Send a request', to: '/requests/new' },
    ],
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
