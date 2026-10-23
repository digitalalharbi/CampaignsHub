import { BarChart3, BellRing, Bot, Building2, ClipboardList, CreditCard, FolderKanban, FolderOpen, Images, Inbox, Layers, LayoutDashboard, Lightbulb, Link2, ListChecks, Megaphone, MessageSquare, Plug, Receipt, RectangleHorizontal, Rocket, Rows3, Send, Settings, ShieldCheck, TrendingUp, Users, Wallet, Workflow } from 'lucide-react'
import type { NavGroup } from './SidebarNav'

/**
 * The agency portal's navigation (`/agency`), grouped by what somebody came to DO.
 *
 * An agency's question is «how are my clients doing?», not «how is my campaign doing?». Clients is
 * therefore the axis everything else hangs off, and Team & permissions — who may see which client —
 * is a section the advertiser portal has no equivalent of.
 *
 * ## What changed, and why (SIMPLIFY-002)
 *
 * The rail was seven groups over fifteen links, and the GROUPING was the problem rather than the
 * count. Two groups carried almost all of it under names that describe nothing a person came to do:
 *
 * - **«العمل / Work»** held requests, projects, campaigns and content. Every one of those is work.
 * - **«التشغيل / Operations»** held tasks, conversations, files, reports AND alerts — five unrelated
 *   things in a bag whose label is an internal category. Reports, which is most of what an agency
 *   hands its clients, was the fourth item inside it.
 *
 * Somebody looking for last month's report had to know it lived under «Operations». That is a menu
 * organised around the system rather than around its reader.
 *
 * The groups are now named for the job: the clients and their work, the campaigns running, the queue
 * of things waiting on a person, what gets handed over, the money, and the setup. Nothing moved out
 * of reach — **all fifteen destinations are still here and every path is unchanged**, so bookmarks
 * and deep links keep working. What changed is which heading they sit under.
 *
 * Two levels, never three. A group of one renders as a plain link (see `SidebarNav`), so «الرئيسية»
 * is a single entry rather than a disclosure triangle over one item.
 *
 * ## Three leaves state a project capability — TEAM-PROJECT-RBAC-001
 *
 * Reports, platform connections and the team screen are the three whose routes refuse somebody
 * without the matching capability in the project they are looking at. A rail that offers them anyway
 * sends a media buyer to a 403, which reads as a broken product rather than as a boundary.
 *
 * The other leaves state none, and that is a decision rather than an omission: every reader who can
 * reach a project can read its dashboard, its campaigns and its content, so a capability on those
 * would be a filter that never filters. A leaf gains one when its route gains one.
 *
 * `agencyNavLeafPaths` is exported so a test can assert every destination survived the regrouping.
 */
/*
 * NAV-FIVE-AREAS-001 — five areas, named for the five jobs an operator does in a day.
 *
 *   Command Center      — what needs a person now: home, requests, tasks, conversations, alerts.
 *   Campaign management — the things being run: projects, campaigns, content, links, spend limits.
 *   AI agents           — the agents, their proposals waiting for approval, and the recommendations.
 *   Analytics & reports — how it is going and what the client receives.
 *   Business management — clients, money, connections, team and settings.
 *
 * REGROUPED, not redesigned: every destination, address, permission gate (`cap`) and label of the
 * previous rail is here (the navGrouping guard pins the set), so no page, link or bookmark moved.
 * NAV-IA-001 still holds inside the second area — the projects directory and the portfolio overview
 * sit together and are named so neither reads as a version of the other.
 */
/*
 * NAV-TAXONOMY-001 — the same four areas as the advertiser rail plus the agency's own «العملاء», short familiar words,
 * campaign work first in the approved order. Regrouped and renamed, never removed (navGrouping.test.ts): the clients,
 * requests, conversations and invoices stay together because they are one job — serving a client.
 */
export const agencyNavGroups: readonly NavGroup[] = [
  {
    key: 'campaigns',
    ar: 'إدارة الحملات', en: 'Campaign management', icon: Megaphone,
    leaves: [
      { to: '/agency/dashboard', ar: 'نظرة عامة', en: 'Overview', icon: LayoutDashboard },
      { to: '/agency/projects', ar: 'المشاريع', en: 'Projects', icon: FolderKanban },
      { to: '/agency/launch', ar: 'إطلاق الحملات', en: 'Launch', icon: Rocket },
      { to: '/agency/campaigns', ar: 'الحملات', en: 'Campaigns', icon: Megaphone },
      { to: '/agency/ad-groups', ar: 'المجموعات', en: 'Ad groups', icon: Rows3 },
      { to: '/agency/ads', ar: 'الإعلانات', en: 'Ads', icon: RectangleHorizontal },
      { to: '/agency/content', ar: 'المحتويات', en: 'Content', icon: Images },
    ],
  },
  {
    key: 'performance',
    ar: 'الأداء', en: 'Performance', icon: TrendingUp,
    leaves: [
      { to: '/agency/analytics', ar: 'التحليلات', en: 'Analytics', icon: TrendingUp },
      { to: '/agency/reports', ar: 'التقارير', en: 'Reports', icon: BarChart3, cap: 'reports.view' },
      { to: '/agency/delivery', ar: 'التسليم', en: 'Delivery', icon: Send, cap: 'reports.view' },
      { to: '/agency/portfolio', ar: 'الملخص', en: 'Portfolio', icon: Layers },
      { to: '/agency/short-links', ar: 'الروابط', en: 'Links', icon: Link2 },
      { to: '/agency/forms', ar: 'النماذج', en: 'Forms', icon: ClipboardList },
      { to: '/agency/files', ar: 'الملفات', en: 'Files', icon: FolderOpen },
    ],
  },
  {
    key: 'operations',
    ar: 'العمليات', en: 'Operations', icon: ListChecks,
    leaves: [
      { to: '/agency/tasks', ar: 'المهام', en: 'Tasks', icon: ListChecks },
      { to: '/agency/alerts', ar: 'التنبيهات', en: 'Alerts', icon: BellRing },
      { to: '/agency/automation', ar: 'الأتمتة', en: 'Automation', icon: Workflow },
      { to: '/agency/agents/approvals', ar: 'الموافقات', en: 'Approvals', icon: ShieldCheck },
      { to: '/agency/agents', ar: 'الوكلاء', en: 'Agents', icon: Bot, end: true },
      { to: '/agency/recommendations', ar: 'التوصيات', en: 'Recommendations', icon: Lightbulb },
      { to: '/agency/spend-limits', ar: 'الميزانية', en: 'Budget', icon: Wallet },
    ],
  },
  {
    key: 'clients',
    ar: 'خدمة العملاء', en: 'Client service', icon: Building2,
    leaves: [
      { to: '/agency/clients', ar: 'العملاء', en: 'Clients', icon: Building2 },
      { to: '/agency/requests', ar: 'الطلبات', en: 'Requests', icon: Inbox },
      { to: '/agency/messages', ar: 'المحادثات', en: 'Conversations', icon: MessageSquare },
      { to: '/agency/billing', ar: 'الفواتير', en: 'Invoices', icon: Receipt },
    ],
  },
  {
    key: 'business',
    ar: 'الحساب', en: 'Account', icon: Settings,
    leaves: [
      { to: '/agency/integrations', ar: 'التكاملات', en: 'Integrations', icon: Plug, cap: 'integrations.manage' },
      { to: '/agency/subscriptions', ar: 'الاشتراك', en: 'Subscription', icon: CreditCard },
      { to: '/agency/team', ar: 'الفريق', en: 'Team', icon: Users, cap: 'team.manage' },
      { to: '/agency/settings', ar: 'الإعدادات', en: 'Settings', icon: Settings },
    ],
  },
]

export const agencyNavLeafPaths: readonly string[] = agencyNavGroups.flatMap((g) => g.leaves.map((l) => l.to))
