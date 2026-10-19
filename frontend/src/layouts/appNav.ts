import {
  Workflow,
  Bot,
  Layers,
  Link2,
  BarChart3,
  BellRing,
  CreditCard,
  FolderKanban,
  FolderOpen,
  Images,
  LayoutDashboard,
  Lightbulb,
  ListChecks,
  Megaphone,
  Plug,
  Settings,
  TrendingUp,
  Wallet,
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
/*
 * NAV-FIVE-AREAS-001 — the same five areas as the agency rail, holding only the advertiser's own
 * destinations (REG-001: no clients, requests, conversations or client invoicing here). Regrouped,
 * not redesigned: every address, entitlement (`ent`) and page is unchanged.
 */
export const appNavGroups: readonly NavGroup[] = [
  {
    key: 'command',
    ar: 'مركز القيادة', en: 'Command Center', icon: LayoutDashboard,
    leaves: [
      { to: '/app/dashboard', ar: 'لوحة التحكم', en: 'Dashboard', icon: LayoutDashboard, ent: 'dashboard' },
      { to: '/app/tasks', ar: 'المهام', en: 'Tasks', icon: ListChecks, ent: 'tasks' },
      { to: '/app/alerts', ar: 'التنبيهات', en: 'Alerts', icon: BellRing, ent: 'alerts' },
    ],
  },
  {
    key: 'campaigns',
    ar: 'إدارة الحملات', en: 'Campaign management', icon: Megaphone,
    leaves: [
      { to: '/app/projects', ar: 'المشاريع', en: 'Projects', icon: FolderKanban, ent: 'projects' },
      { to: '/app/portfolio', ar: 'الملخص الشامل', en: 'Portfolio overview', icon: Layers, ent: 'projects' },
      { to: '/app/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone, ent: 'campaigns' },
      { to: '/app/content', ar: 'المحتويات', en: 'Content', icon: Images, ent: 'content' },
      { to: '/app/short-links', ar: 'اختصار الروابط', en: 'Short Links', icon: Link2, ent: 'campaigns' },
      { to: '/app/spend-limits', ar: 'حدود الإنفاق', en: 'Spend limits', icon: Wallet, ent: 'analytics' },
    ],
  },
  {
    key: 'agents',
    ar: 'الوكلاء الأذكياء', en: 'AI agents', icon: Bot,
    leaves: [
      { to: '/app/automation', ar: 'القواعد الآلية', en: 'Automated rules', icon: Workflow, ent: 'analytics' },
      { to: '/app/agents', ar: 'مركز الوكلاء', en: 'Agent Center', icon: Bot, ent: 'analytics' },
      { to: '/app/recommendations', ar: 'التوصيات', en: 'Recommendations', icon: Lightbulb, ent: 'analytics' },
    ],
  },
  {
    key: 'analytics',
    ar: 'التحليلات والتقارير', en: 'Analytics & reports', icon: BarChart3,
    leaves: [
      { to: '/app/analytics', ar: 'التحليلات', en: 'Analytics', icon: TrendingUp, ent: 'analytics' },
      { to: '/app/reports', ar: 'التقارير', en: 'Reports', icon: BarChart3, ent: 'reports' },
      { to: '/app/files', ar: 'الملفات', en: 'Files', icon: FolderOpen, ent: 'files' },
    ],
  },
  {
    key: 'business',
    // Connecting a platform is the FIRST thing a new workspace does, so it leads the area.
    ar: 'إدارة الأعمال', en: 'Business management', icon: Settings,
    leaves: [
      { to: '/app/integrations', ar: 'التكاملات', en: 'Integrations', icon: Plug, ent: 'connections' },
      { to: '/app/subscriptions', ar: 'الاشتراك', en: 'Subscription', icon: CreditCard, ent: 'subscriptions' },
      { to: '/app/settings', ar: 'إعدادات مساحة العمل', en: 'Workspace settings', icon: Settings, ent: 'settings' },
    ],
  },
]

export const appNavLeafPaths: readonly string[] = appNavGroups.flatMap((g) => g.leaves.map((l) => l.to))
