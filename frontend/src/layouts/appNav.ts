import {
  BarChart3, BellRing, CreditCard, FolderKanban, FolderOpen, Images,
  LayoutDashboard, ListChecks, Megaphone, Plug, Settings, TrendingUp,
} from 'lucide-react'
import type { NavGroup } from './SidebarNav'

/**
 * The advertiser portal's navigation (`/app`), grouped around its own purpose:
 * «كل حملاتك الإعلانية المدفوعة في مكان واحد» — every paid campaign of YOUR OWN, in one place.
 *
 *   Work        — the things being run: projects, campaigns, creative.
 *   Performance — how they are doing: analytics, reports, and the alerts that interrupt you.
 *   Operations  — what keeps them running: tasks, files, data sources.
 *   Finance     — this workspace's own subscription.
 *
 * What is ABSENT is the point of REG-001. Requests, Clients, agency Billing and Conversations were
 * listed here, and they are the agency's: each of them presumes you run campaigns for other people.
 * With them in place an advertiser signing in met a multi-client agency console, which is what made
 * every portal feel like the same product. They moved to `/agency`, where that is the purpose —
 * moved, not deleted, and not duplicated. Old `/app` paths redirect there.
 *
 * `appNavLeafPaths` is exported so a test can assert what this rail offers, and
 * `frontend/src/layouts/portalNav.test.ts` asserts it is not the agency's.
 */
export const appNavGroups: readonly NavGroup[] = [
  {
    key: 'overview',
    ar: 'لوحة التحكم', en: 'Dashboard', icon: LayoutDashboard,
    leaves: [{ to: '/app/dashboard', ar: 'لوحة التحكم', en: 'Dashboard', icon: LayoutDashboard, ent: 'dashboard' }],
  },
  {
    key: 'work',
    ar: 'العمل', en: 'Work', icon: Megaphone,
    leaves: [
      { to: '/app/projects', ar: 'المشاريع', en: 'Projects', icon: FolderKanban, ent: 'projects' },
      { to: '/app/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone, ent: 'campaigns' },
      { to: '/app/content', ar: 'المحتوى', en: 'Content', icon: Images, ent: 'content' },
    ],
  },
  {
    key: 'performance',
    ar: 'الأداء', en: 'Performance', icon: TrendingUp,
    leaves: [
      { to: '/app/analytics', ar: 'التحليلات', en: 'Analytics', icon: TrendingUp, ent: 'analytics' },
      { to: '/app/reports', ar: 'التقارير', en: 'Reports', icon: BarChart3, ent: 'reports' },
      { to: '/app/alerts', ar: 'التنبيهات', en: 'Alerts', icon: BellRing, ent: 'alerts' },
    ],
  },
  {
    key: 'operations',
    ar: 'التشغيل', en: 'Operations', icon: ListChecks,
    leaves: [
      { to: '/app/tasks', ar: 'المهام', en: 'Tasks', icon: ListChecks, ent: 'tasks' },
      { to: '/app/files', ar: 'الملفات', en: 'Files', icon: FolderOpen, ent: 'files' },
      { to: '/app/integrations', ar: 'التكاملات', en: 'Integrations', icon: Plug, ent: 'connections' },
    ],
  },
  {
    key: 'finance',
    // The advertiser's money is what they pay CampaignsHub. Invoices raised to a client are the
    // agency's Finance, and live in the agency portal.
    ar: 'الاشتراك', en: 'Subscription', icon: CreditCard,
    leaves: [
      { to: '/app/subscriptions', ar: 'الاشتراك', en: 'Subscription', icon: CreditCard, ent: 'subscriptions' },
    ],
  },
  {
    key: 'settings',
    // WORKSPACE settings. Personal settings live in the account menu and nowhere else; system
    // settings live in /admin. This entry is the workspace's own.
    ar: 'إعدادات مساحة العمل', en: 'Workspace settings', icon: Settings,
    leaves: [{ to: '/app/settings', ar: 'إعدادات مساحة العمل', en: 'Workspace settings', icon: Settings, ent: 'settings' }],
  },
]

/** Every destination the advertiser rail offers. The flat rail had exactly these. */
export const appNavLeafPaths: readonly string[] = appNavGroups.flatMap((g) => g.leaves.map((l) => l.to))
