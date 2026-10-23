import { BarChart3, BellRing, Bot, ClipboardList, CreditCard, FolderKanban, FolderOpen, Images, Layers, LayoutDashboard, Lightbulb, Link2, ListChecks, Megaphone, Plug, RectangleHorizontal, Rocket, Rows3, Settings, ShieldCheck, TrendingUp, Wallet, Workflow } from 'lucide-react'
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
/*
 * NAV-TAXONOMY-001 — four areas, short familiar words, campaign work first in the order the Owner approved:
 * نظرة عامة · المشاريع · إطلاق الحملات · الحملات · المجموعات · الإعلانات.
 *
 * Regrouped and renamed, never removed: every address this rail offered before is still here (navGrouping.test.ts
 * proves it). «إطلاق الحملات» opened the internal campaign draft form until LAUNCH-001 gave it a page of its own
 * (/launch) that publishes on the platforms' APIs; «المجموعات» / «الإعلانات» opened the Analytics ad-set and ad tabs until
 * ADV-CAMPAIGN-TABLES-001 gave each rung an operational table of its own (/ad-groups, /ads). Approvals, which had a
 * page and no entry, now has one.
 */
export const appNavGroups: readonly NavGroup[] = [
  {
    key: 'campaigns',
    ar: 'إدارة الحملات', en: 'Campaign management', icon: Megaphone,
    leaves: [
      { to: '/app/dashboard', ar: 'نظرة عامة', en: 'Overview', icon: LayoutDashboard, ent: 'dashboard' },
      { to: '/app/projects', ar: 'المشاريع', en: 'Projects', icon: FolderKanban, ent: 'projects' },
      { to: '/app/launch', ar: 'إطلاق الحملات', en: 'Launch', icon: Rocket, ent: 'campaigns' },
      { to: '/app/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone, ent: 'campaigns' },
      { to: '/app/ad-groups', ar: 'المجموعات', en: 'Ad groups', icon: Rows3, ent: 'analytics' },
      { to: '/app/ads', ar: 'الإعلانات', en: 'Ads', icon: RectangleHorizontal, ent: 'analytics' },
      { to: '/app/content', ar: 'المحتويات', en: 'Content', icon: Images, ent: 'content' },
    ],
  },
  {
    key: 'performance',
    ar: 'الأداء', en: 'Performance', icon: TrendingUp,
    leaves: [
      { to: '/app/analytics', ar: 'التحليلات', en: 'Analytics', icon: TrendingUp, ent: 'analytics' },
      { to: '/app/reports', ar: 'التقارير', en: 'Reports', icon: BarChart3, ent: 'reports' },
      { to: '/app/portfolio', ar: 'الملخص', en: 'Portfolio', icon: Layers, ent: 'projects' },
      { to: '/app/short-links', ar: 'الروابط', en: 'Links', icon: Link2, ent: 'campaigns' },
      { to: '/app/forms', ar: 'النماذج', en: 'Forms', icon: ClipboardList, ent: 'campaigns' },
      { to: '/app/files', ar: 'الملفات', en: 'Files', icon: FolderOpen, ent: 'files' },
    ],
  },
  {
    key: 'operations',
    ar: 'العمليات', en: 'Operations', icon: ListChecks,
    leaves: [
      { to: '/app/tasks', ar: 'المهام', en: 'Tasks', icon: ListChecks, ent: 'tasks' },
      { to: '/app/alerts', ar: 'التنبيهات', en: 'Alerts', icon: BellRing, ent: 'alerts' },
      { to: '/app/automation', ar: 'الأتمتة', en: 'Automation', icon: Workflow, ent: 'analytics' },
      { to: '/app/agents/approvals', ar: 'الموافقات', en: 'Approvals', icon: ShieldCheck, ent: 'analytics' },
      { to: '/app/agents', ar: 'الوكلاء', en: 'Agents', icon: Bot, ent: 'analytics', end: true },
      { to: '/app/recommendations', ar: 'التوصيات', en: 'Recommendations', icon: Lightbulb, ent: 'analytics' },
      { to: '/app/spend-limits', ar: 'الميزانية', en: 'Budget', icon: Wallet, ent: 'analytics' },
    ],
  },
  {
    key: 'business',
    ar: 'الحساب', en: 'Account', icon: Settings,
    leaves: [
      { to: '/app/integrations', ar: 'التكاملات', en: 'Integrations', icon: Plug, ent: 'connections' },
      { to: '/app/subscriptions', ar: 'الاشتراك', en: 'Subscription', icon: CreditCard, ent: 'subscriptions' },
      { to: '/app/settings', ar: 'الإعدادات', en: 'Settings', icon: Settings, ent: 'settings' },
    ],
  },
]

export const appNavLeafPaths: readonly string[] = appNavGroups.flatMap((g) => g.leaves.map((l) => l.to))
