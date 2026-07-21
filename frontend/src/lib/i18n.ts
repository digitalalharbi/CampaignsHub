import { useUi } from '@/stores/ui'

/** Minimal bilingual dictionary. Real i18n (per-domain namespaces) arrives with the design system. */
const dictionary = {
  ar: {
    app_name: 'منصة الميديا باينج',
    dashboard: 'لوحة التحكم',
    system_status: 'حالة النظام',
    clients: 'العملاء',
    campaigns: 'الحملات',
    reports: 'التقارير',
    settings: 'الإعدادات',
    design: 'نظام التصميم',
    sign_in: 'تسجيل الدخول',
    sign_out: 'تسجيل الخروج',
    email: 'البريد الإلكتروني',
    password: 'كلمة المرور',
    welcome_back: 'مرحباً بعودتك',
    sign_in_subtitle: 'ادخل إلى مساحة عملك',
    liveness: 'حيوية الخدمة',
    readiness: 'جاهزية الاعتماديات',
    database: 'قاعدة البيانات',
    redis: 'ريديس',
    last_updated: 'آخر تحديث',
    data_source: 'مصدر البيانات',
    up: 'يعمل',
    down: 'متوقف',
    loading: 'جارٍ التحميل…',
    error: 'حدث خطأ',
    healthy: 'سليم',
  },
  en: {
    app_name: 'MediaBuying Platform',
    dashboard: 'Dashboard',
    system_status: 'System Status',
    clients: 'Clients',
    campaigns: 'Campaigns',
    reports: 'Reports',
    settings: 'Settings',
    design: 'Design System',
    sign_in: 'Sign in',
    sign_out: 'Sign out',
    email: 'Email',
    password: 'Password',
    welcome_back: 'Welcome back',
    sign_in_subtitle: 'Sign in to your workspace',
    liveness: 'Service liveness',
    readiness: 'Dependency readiness',
    database: 'Database',
    redis: 'Redis',
    last_updated: 'Last updated',
    data_source: 'Data source',
    up: 'Up',
    down: 'Down',
    loading: 'Loading…',
    error: 'Something went wrong',
    healthy: 'Healthy',
  },
} as const

export type TranslationKey = keyof (typeof dictionary)['en']

export function useT() {
  const locale = useUi((s) => s.locale)
  return (key: TranslationKey): string => dictionary[locale][key]
}
